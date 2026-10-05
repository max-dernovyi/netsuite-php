<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

final class AccessToken
{
    /** @var string */
    private $value;
    /** @var int */
    private $expiresAt;

    /**
     * @param int $expiresAt Unix time when NetSuite stops accepting the token
     */
    public function __construct(string $value, int $expiresAt)
    {
        $this->value = $value;
        $this->expiresAt = $expiresAt;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }
}
