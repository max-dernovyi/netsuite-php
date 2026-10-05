<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

final class Response
{
    use HasHeaders;

    /** @var int */
    private $statusCode;
    /** @var string */
    private $body;

    public function __construct(int $statusCode, array $headers = [], string $body = '')
    {
        $this->statusCode = $statusCode;
        $this->setHeaders($headers);
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * The body decoded as a JSON object or array; null when empty or not JSON.
     */
    public function json(): ?array
    {
        if (trim($this->body) === '') {
            return null;
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }
}
