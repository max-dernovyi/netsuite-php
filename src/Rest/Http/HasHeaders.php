<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

/**
 * Header storage with case-insensitive lookup; the original spelling is kept for sending.
 */
trait HasHeaders
{
    /** @var array<string, array{0: string, 1: string}> lower-case name => [name, value] */
    private $headers = [];

    private function setHeaders(array $headers): void
    {
        $this->headers = [];
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = [(string) $name, (string) $value];
        }
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $headers = [];
        foreach ($this->headers as $header) {
            $headers[$header[0]] = $header[1];
        }
        return $headers;
    }

    public function getHeader(string $name): ?string
    {
        $key = strtolower($name);
        return isset($this->headers[$key]) ? $this->headers[$key][1] : null;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }
}
