<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

/**
 * A REST request body and the sublists to send as `?replace=`.
 */
final class SerializedRecord
{
    /** @var array<string, mixed> */
    private $body;
    /** @var string[] */
    private $replace;

    /**
     * @param array<string, mixed> $body
     * @param string[] $replace
     */
    public function __construct(array $body, array $replace)
    {
        $this->body = $body;
        $this->replace = $replace;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    /** @return string[] REST sublist names */
    public function replace(): array
    {
        return $this->replace;
    }
}
