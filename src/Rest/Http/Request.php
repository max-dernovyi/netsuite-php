<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

final class Request
{
    use HasHeaders;

    const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var string */
    private $method;
    /** @var string */
    private $url;
    /** @var string|null */
    private $body;

    public function __construct(string $method, string $url, array $headers = [], ?string $body = null)
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported HTTP method "%s"', $method));
        }
        $this->method = $method;
        $this->url = $url;
        $this->setHeaders($headers);
        $this->body = $body;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Returns a copy with the header set, replacing any spelling of the same name.
     */
    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[strtolower($name)] = [$name, $value];
        return $clone;
    }

    /**
     * @return string[] "Name: value" lines
     */
    public function getHeaderLines(): array
    {
        $lines = [];
        foreach ($this->headers as $header) {
            $lines[] = $header[0] . ': ' . $header[1];
        }
        return $lines;
    }
}
