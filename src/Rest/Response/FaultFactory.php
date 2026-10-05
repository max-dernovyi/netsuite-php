<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Response;

use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;

/**
 * Picks the SOAP fault for failures SOAP reports as faults; other errors stay statuses.
 */
final class FaultFactory
{
    /**
     * @return RestFault|null null when the HTTP status maps to a status, not a fault (2xx–4xx except 401 and 429)
     */
    public function forHttpStatus(int $httpStatus, string $message): ?RestFault
    {
        if ($httpStatus === 401) {
            return RestFault::invalidCredentials($message, $httpStatus);
        }
        if ($httpStatus === 429) {
            return RestFault::exceededConcurrentRequestLimit($message, $httpStatus);
        }
        if ($httpStatus >= 500) {
            return RestFault::unexpectedError($message, $httpStatus);
        }
        return null;
    }

    public function fromError(RestError $error): ?RestFault
    {
        return $this->forHttpStatus($error->getHttpStatus(), $error->getMessage());
    }

    /**
     * @param string|null $message replaces the transport message, e.g. with request context
     */
    public function fromTransport(TransportException $error, ?string $message = null): RestFault
    {
        return RestFault::unexpectedError($message ?? $error->getMessage());
    }
}
