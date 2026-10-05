<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

use NetSuite\Rest\Auth\AuthenticatorInterface;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Response\FaultFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sends signed JSON requests to the REST API with retries, logging and call recording.
 *
 * Returns 2xx and business 4xx responses; throws RestFault for 401, 429, 5xx and transport
 * failures once retries are used up.
 */
final class RestClient
{
    const RETRYABLE_METHODS = ['GET', 'PUT', 'DELETE'];
    const BACKOFF_BASE = 0.5;
    const BACKOFF_MAX = 30.0;
    /** A longer Retry-After fails the call instead of blocking the process. */
    const RETRY_AFTER_MAX = 60;

    const REDACTED = '[redacted]';
    const SECRET_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];
    const SECRET_KEYS = [
        'access_token', 'refresh_token', 'id_token', 'client_assertion', 'client_secret',
        'password', 'password2', 'currentpassword', 'newpassword', 'newpassword2',
        'ccnumber', 'ccsecuritycode', 'socialsecuritynumber',
    ];

    /** @var RestConfig */
    private $config;
    /** @var TransportInterface */
    private $transport;
    /** @var AuthenticatorInterface */
    private $auth;
    /** @var LoggerInterface */
    private $logger;
    /** @var SleeperInterface */
    private $sleeper;
    /** @var CallRecorder */
    private $recorder;
    /** @var callable(): float */
    private $random;
    /** @var callable(): int */
    private $clock;
    /** @var bool */
    private $logging;
    /** @var ErrorParser */
    private $errors;
    /** @var FaultFactory */
    private $faults;

    /**
     * @param callable|null $random returns a float in [0, 1) for backoff jitter
     * @param callable|null $clock  returns the current Unix time; time() by default
     */
    public function __construct(
        RestConfig $config,
        TransportInterface $transport,
        AuthenticatorInterface $auth,
        ?LoggerInterface $logger = null,
        ?SleeperInterface $sleeper = null,
        ?CallRecorder $recorder = null,
        ?callable $random = null,
        ?callable $clock = null
    ) {
        $this->config = $config;
        $this->transport = $transport;
        $this->auth = $auth;
        $this->logger = $logger ?: new NullLogger();
        $this->sleeper = $sleeper ?: new NativeSleeper();
        $this->recorder = $recorder ?: new CallRecorder();
        $this->random = $random ?: function () {
            return mt_rand() / (mt_getrandmax() + 1);
        };
        $this->clock = $clock ?: 'time';
        $this->logging = $config->logging();
        $this->errors = new ErrorParser();
        $this->faults = new FaultFactory();
    }

    public function setLogging(bool $on): void
    {
        $this->logging = $on;
    }

    public function getRecorder(): CallRecorder
    {
        return $this->recorder;
    }

    /**
     * @param string $path relative to the REST base URL, e.g. `/record/v1/customer/42`
     * @param array<string, scalar|string[]|null> $query booleans become `true`/`false`, lists are comma-joined
     * @param array<string, string> $headers override the JSON defaults
     * @throws RestFault on 401, 429, 5xx or a transport failure after retries
     */
    public function send(string $method, string $path, array $query = [], ?array $json = null, array $headers = []): Response
    {
        $request = new Request(
            $method,
            $this->url($path, $query),
            $this->headers($json !== null, $headers),
            $json === null ? null : $this->encode($json)
        );
        $maxAttempts = in_array($request->getMethod(), self::RETRYABLE_METHODS, true) ? $this->config->maxAttempts() : 1;
        $reauthorized = false;
        $attempt = 1;

        while (true) {
            $signed = $request;
            try {
                $signed = $this->auth->authorize($request);
                $response = $this->transport->send($signed);
            } catch (TransportException $e) {
                $this->record($signed, null, $e);
                if ($attempt >= $maxAttempts) {
                    throw $this->faults->fromTransport($e, $this->failure($request, $attempt, $e->getMessage()));
                }
                $this->sleeper->sleep($this->backoff($attempt++));
                continue;
            }
            $this->record($signed, $response);

            $status = $response->getStatusCode();
            if ($status === 401 && !$reauthorized) {
                // A stale token or clock skew: refresh credentials and resend; does not count as an attempt.
                $reauthorized = true;
                $this->auth->invalidate();
                continue;
            }
            if ($status !== 401 && $status !== 429 && $status < 500) {
                return $response;
            }
            if ($status === 401 || $attempt >= $maxAttempts) {
                throw $this->fault($request, $response, $attempt);
            }

            $delay = $status === 429 ? $this->retryAfter($response) : null;
            if ($delay === null) {
                $delay = $this->backoff($attempt);
            } elseif ($delay > self::RETRY_AFTER_MAX) {
                throw $this->fault($request, $response, $attempt);
            }
            $this->sleeper->sleep($delay);
            $attempt++;
        }
    }

    private function url(string $path, array $query): string
    {
        $url = $this->config->baseUrl().'/'.ltrim($path, '/');
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = implode(',', $value);
            }
            $pairs[] = rawurlencode((string) $name).'='.rawurlencode((string) $value);
        }
        return $pairs ? $url.'?'.implode('&', $pairs) : $url;
    }

    /**
     * @return array<string, string>
     */
    private function headers(bool $hasBody, array $headers): array
    {
        $defaults = ['Accept' => 'application/json'];
        if ($hasBody) {
            $defaults['Content-Type'] = 'application/json';
        }
        // Request keeps the last spelling of a name, so caller headers win.
        return array_merge($defaults, $headers);
    }

    private function encode(array $json): string
    {
        if ($json === []) {
            return '{}';
        }
        $body = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) {
            throw new \InvalidArgumentException('Cannot encode the request body as JSON: '.json_last_error_msg());
        }
        return $body;
    }

    private function backoff(int $attempt): float
    {
        $delay = min(self::BACKOFF_MAX, self::BACKOFF_BASE * (2 ** ($attempt - 1)));
        return $delay / 2 + (float) call_user_func($this->random) * $delay / 2;
    }

    /**
     * Seconds to wait from a Retry-After header (delta-seconds or an HTTP date); null when absent or unreadable.
     */
    private function retryAfter(Response $response): ?float
    {
        $value = trim((string) $response->getHeader('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (float) $value;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }
        return (float) max(0, $date->getTimestamp() - (int) call_user_func($this->clock));
    }

    private function fault(Request $request, Response $response, int $attempt): RestFault
    {
        $status = $response->getStatusCode();
        $message = $this->failure($request, $attempt, 'HTTP '.$status.': '.$this->errors->parse($response)->getMessage());
        return $this->faults->forHttpStatus($status, $message) ?: RestFault::unexpectedError($message, $status);
    }

    private function failure(Request $request, int $attempt, string $reason): string
    {
        return sprintf(
            'NetSuite REST %s %s failed%s: %s',
            $request->getMethod(),
            $request->getUrl(),
            $attempt > 1 ? ' after '.$attempt.' attempts' : '',
            $reason
        );
    }

    private function record(Request $request, ?Response $response, ?TransportException $error = null): void
    {
        $this->recorder->record($request, $response);
        if (!$this->logging) {
            return;
        }

        $message = $this->describe($request->getMethod().' '.$request->getUrl(), $request->getHeaders(), $request->getBody())
            ."\n\n"
            .($response !== null
                ? $this->describe('HTTP '.$response->getStatusCode(), $response->getHeaders(), $response->getBody())
                : 'No response: '.($error !== null ? $error->getMessage() : 'unknown error'));
        $this->logger->info($message, [
            'operation' => 'rest',
            'method'    => $request->getMethod(),
            'url'       => $request->getUrl(),
            'status'    => $response !== null ? $response->getStatusCode() : null,
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function describe(string $startLine, array $headers, ?string $body): string
    {
        $lines = [$startLine];
        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.(in_array(strtolower($name), self::SECRET_HEADERS, true) ? self::REDACTED : $value);
        }
        $text = implode("\n", $lines);
        if ($body !== null && $body !== '') {
            $text .= "\n\n".$this->redactBody($body);
        }
        return $text;
    }

    private function redactBody(string $body): string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return $body;
        }
        $redacted = false;
        $data = $this->redactValues($data, $redacted);
        if (!$redacted) {
            return $body;
        }
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function redactValues(array $data, bool &$redacted): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true)) {
                $data[$key] = self::REDACTED;
                $redacted = true;
            } elseif (is_array($value)) {
                $data[$key] = $this->redactValues($value, $redacted);
            }
        }
        return $data;
    }
}
