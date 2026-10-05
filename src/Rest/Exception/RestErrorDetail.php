<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Exception;

/**
 * One entry of a REST error's `o:errorDetails`.
 */
final class RestErrorDetail
{
    /** @var string */
    private $detail;
    /** @var string|null */
    private $errorCode;
    /** @var string|null */
    private $errorPath;

    public function __construct(string $detail, ?string $errorCode = null, ?string $errorPath = null)
    {
        $this->detail = $detail;
        $this->errorCode = $errorCode;
        $this->errorPath = $errorPath;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getErrorPath(): ?string
    {
        return $this->errorPath;
    }
}
