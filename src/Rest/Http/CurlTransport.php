<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

use NetSuite\Rest\Exception\TransportException;

final class CurlTransport implements TransportInterface
{
    const DEFAULT_CONNECT_TIMEOUT = 10;

    /** @var float */
    private $timeout;
    /** @var float */
    private $connectTimeout;

    /**
     * @param int|float $timeout total timeout in seconds
     * @param int|float $connectTimeout connect timeout in seconds, capped by $timeout
     */
    public function __construct($timeout = 60, $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT)
    {
        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw new \InvalidArgumentException('Timeouts must be positive');
        }
        $this->timeout = (float) $timeout;
        $this->connectTimeout = (float) min($connectTimeout, $timeout);
    }

    public function send(Request $request): Response
    {
        $headers = [];
        $handle = curl_init();
        $options = [
            CURLOPT_URL            => $request->getUrl(),
            CURLOPT_CUSTOMREQUEST  => $request->getMethod(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS     => (int) ceil($this->timeout * 1000),
            // An empty Expect stops curl waiting for "100 Continue" on large bodies.
            CURLOPT_HTTPHEADER     => array_merge($request->getHeaderLines(), ['Expect:']),
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                if (strncasecmp($line, 'HTTP/', 5) === 0) {
                    // A new status line (after 100 Continue) starts a new header block.
                    $headers = [];
                } elseif (strpos($line, ':') !== false) {
                    list($name, $value) = explode(':', $line, 2);
                    $name = trim($name);
                    $value = trim($value);
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }
                return strlen($line);
            },
        ];
        if ($request->getBody() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->getBody();
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if ($body === false) {
            throw new TransportException(sprintf(
                'cURL error %d: %s (%s %s)',
                curl_errno($handle),
                curl_error($handle),
                $request->getMethod(),
                $request->getUrl()
            ), curl_errno($handle));
        }

        return new Response((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, (string) $body);
    }
}
