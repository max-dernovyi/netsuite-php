<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Exception;

use NetSuite\Classes\FaultCodeType;
use NetSuite\Classes\NSSoapFault;

/**
 * A REST failure shaped like the SOAP fault NetSuite would return, so `catch (\SoapFault)` keeps working.
 * `detail` mirrors ext-soap: `$e->detail->invalidCredentialsFault->code`.
 */
final class RestFault extends \SoapFault
{
    const INVALID_CREDENTIALS = 'InvalidCredentialsFault';
    const EXCEEDED_CONCURRENT_REQUEST_LIMIT = 'ExceededConcurrentRequestLimitFault';
    const UNEXPECTED_ERROR = 'UnexpectedErrorFault';

    const FAULT_CODE = 'soapenv:Server.userException';

    const DEFAULT_CODES = [
        self::INVALID_CREDENTIALS               => FaultCodeType::INVALID_LOGIN_CREDENTIALS,
        self::EXCEEDED_CONCURRENT_REQUEST_LIMIT => FaultCodeType::WS_CONCUR_SESSION_DISALLWD,
        self::UNEXPECTED_ERROR                  => FaultCodeType::UNEXPECTED_ERROR,
    ];

    /** @var NSSoapFault */
    private $fault;
    /** @var int|null */
    private $httpStatus;

    /**
     * @param string $faultName short name of a generated fault class, e.g. InvalidCredentialsFault
     * @param string|null $code a FaultCodeType value; defaults per fault
     */
    public function __construct(string $faultName, string $message, ?string $code = null, ?int $httpStatus = null)
    {
        $class = 'NetSuite\\Classes\\' . $faultName;
        if (!class_exists($class) || !is_subclass_of($class, NSSoapFault::class)) {
            throw new \InvalidArgumentException(sprintf('Unknown NetSuite fault "%s"', $faultName));
        }

        $fault = new $class();
        $fault->code = $code ?? (self::DEFAULT_CODES[$faultName] ?? FaultCodeType::UNEXPECTED_ERROR);
        $fault->message = $message;
        $this->fault = $fault;
        $this->httpStatus = $httpStatus;

        $detail = new \stdClass();
        $detail->{lcfirst($faultName)} = $fault;
        parent::__construct(self::FAULT_CODE, $message, null, $detail, $faultName);
    }

    public static function invalidCredentials(string $message, ?int $httpStatus = null): self
    {
        return new self(self::INVALID_CREDENTIALS, $message, null, $httpStatus);
    }

    public static function exceededConcurrentRequestLimit(string $message, ?int $httpStatus = null): self
    {
        return new self(self::EXCEEDED_CONCURRENT_REQUEST_LIMIT, $message, null, $httpStatus);
    }

    public static function unexpectedError(string $message, ?int $httpStatus = null): self
    {
        return new self(self::UNEXPECTED_ERROR, $message, null, $httpStatus);
    }

    public function getFault(): NSSoapFault
    {
        return $this->fault;
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
