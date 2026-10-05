<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Http\ErrorParser;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\TransportInterface;

/**
 * Exchanges a JWT client assertion for an access token (OAuth 2.0 client credentials).
 */
final class OAuth2TokenClient
{
    const PATH = '/auth/oauth2/v1/token';
    const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';

    /** @var TransportInterface */
    private $transport;
    /** @var string */
    private $tokenUrl;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable|null $clock returns the current Unix time; time() by default
     */
    public function __construct(TransportInterface $transport, string $tokenUrl, ?callable $clock = null)
    {
        $this->transport = $transport;
        $this->tokenUrl = $tokenUrl;
        $this->clock = $clock ?: 'time';
    }

    public static function fromConfig(RestConfig $config, TransportInterface $transport, ?callable $clock = null): self
    {
        return new self($transport, $config->baseUrl().self::PATH, $clock);
    }

    public function getTokenUrl(): string
    {
        return $this->tokenUrl;
    }

    /**
     * @throws RestFault          when NetSuite refuses the assertion or returns an unusable response
     * @throws TransportException when no HTTP response was received
     */
    public function requestToken(string $assertion): AccessToken
    {
        $body = http_build_query([
            'grant_type'            => 'client_credentials',
            'client_assertion_type' => self::ASSERTION_TYPE,
            'client_assertion'      => $assertion,
        ]);
        $response = $this->transport->send(new Request('POST', $this->tokenUrl, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept'       => 'application/json',
        ], $body));

        if (!$response->isSuccessful()) {
            throw $this->fault($response);
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        if (!is_string($token) || $token === '' || !is_numeric($expiresIn) || $expiresIn <= 0) {
            throw RestFault::unexpectedError(
                'NetSuite OAuth 2.0 token response has no access_token or expires_in',
                $response->getStatusCode()
            );
        }

        return new AccessToken($token, (int) call_user_func($this->clock) + (int) $expiresIn);
    }

    private function fault(Response $response): RestFault
    {
        $status = $response->getStatusCode();
        $message = 'NetSuite OAuth 2.0 token request failed: '.$this->errorMessage($response);
        if ($status === 429) {
            return RestFault::exceededConcurrentRequestLimit($message, $status);
        }
        if ($status >= 500) {
            return RestFault::unexpectedError($message, $status);
        }
        return RestFault::invalidCredentials($message, $status);
    }

    /**
     * The token endpoint answers with an RFC 6749 error (`error`, `error_description`) or a REST error body.
     */
    private function errorMessage(Response $response): string
    {
        $data = $response->json();
        if (isset($data['error']) && is_string($data['error'])) {
            $description = isset($data['error_description']) && is_string($data['error_description'])
                ? $data['error_description']
                : '';
            return $description !== '' ? $data['error'].': '.$description : $data['error'];
        }
        return (new ErrorParser())->parse($response)->getMessage();
    }
}
