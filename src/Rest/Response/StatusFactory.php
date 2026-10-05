<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Response;

use NetSuite\Classes\Status;
use NetSuite\Classes\StatusDetail;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\StatusDetailType;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestFault;

/**
 * Builds SOAP `Status` objects from REST outcomes.
 */
final class StatusFactory
{
    /** @var array<string, true>|null */
    private static $codes;

    public function success(): Status
    {
        $status = new Status();
        $status->isSuccess = true;
        return $status;
    }

    public function fromError(RestError $error): Status
    {
        $status = new Status();
        $status->isSuccess = false;
        $status->statusDetail = [];
        foreach ($error->getDetails() as $detail) {
            $status->statusDetail[] = $this->detail(
                $this->code($detail->getErrorCode(), $error->getHttpStatus()),
                $detail->getDetail() !== '' ? $detail->getDetail() : $error->getTitle()
            );
        }
        return $status;
    }

    /**
     * A list item that hit a fault or an unsupported reference after earlier items were sent.
     */
    public function fromException(\Exception $e): Status
    {
        $status = new Status();
        $status->isSuccess = false;
        $code = $e instanceof RestFault
            ? $this->code($e->getFault()->code, 500)
            : StatusDetailCodeType::USER_ERROR;
        $status->statusDetail = [$this->detail($code, $e->getMessage())];
        return $status;
    }

    private function detail(string $code, string $message): StatusDetail
    {
        $statusDetail = new StatusDetail();
        $statusDetail->code = $code;
        $statusDetail->message = $message;
        $statusDetail->type = StatusDetailType::ERROR;
        return $statusDetail;
    }

    /**
     * The REST error code when SOAP knows it, else the generic code for the HTTP status class.
     */
    private function code(?string $errorCode, int $httpStatus): string
    {
        if (self::$codes === null) {
            $constants = (new \ReflectionClass(StatusDetailCodeType::class))->getConstants();
            self::$codes = array_fill_keys(array_map('strval', $constants), true);
        }
        if ($errorCode !== null && isset(self::$codes[$errorCode])) {
            return $errorCode;
        }
        return $httpStatus >= 500 ? StatusDetailCodeType::UNEXPECTED_ERROR : StatusDetailCodeType::USER_ERROR;
    }
}
