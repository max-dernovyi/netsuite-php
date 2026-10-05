<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Response;

use NetSuite\Classes\BaseRef;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\ReadResponseList;
use NetSuite\Classes\Record;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\WriteResponse;
use NetSuite\Classes\WriteResponseList;
use NetSuite\Rest\Exception\RestError;

/**
 * Builds the SOAP read and write responses REST handlers return.
 */
final class ResponseBuilder
{
    /** @var StatusFactory */
    private $statuses;

    public function __construct(?StatusFactory $statuses = null)
    {
        $this->statuses = $statuses ?: new StatusFactory();
    }

    public function recordRef(?string $internalId, ?string $externalId = null, ?string $type = null): RecordRef
    {
        $ref = new RecordRef();
        $ref->internalId = $internalId;
        $ref->externalId = $externalId;
        $ref->type = $type;
        return $ref;
    }

    public function writeSuccess(BaseRef $baseRef): WriteResponse
    {
        $response = new WriteResponse();
        $response->status = $this->statuses->success();
        $response->baseRef = $baseRef;
        return $response;
    }

    public function writeFailure(RestError $error, ?BaseRef $baseRef = null): WriteResponse
    {
        $response = new WriteResponse();
        $response->status = $this->statuses->fromError($error);
        $response->baseRef = $baseRef;
        return $response;
    }

    /**
     * @param WriteResponse[] $responses
     */
    public function writeList(array $responses): WriteResponseList
    {
        $list = new WriteResponseList();
        $list->status = $this->statuses->success();
        $list->writeResponse = array_values($responses);
        return $list;
    }

    public function readSuccess(Record $record): ReadResponse
    {
        $response = new ReadResponse();
        $response->status = $this->statuses->success();
        $response->record = $record;
        return $response;
    }

    public function readFailure(RestError $error): ReadResponse
    {
        $response = new ReadResponse();
        $response->status = $this->statuses->fromError($error);
        return $response;
    }

    /**
     * @param ReadResponse[] $responses
     */
    public function readList(array $responses): ReadResponseList
    {
        $list = new ReadResponseList();
        $list->status = $this->statuses->success();
        $list->readResponse = array_values($responses);
        return $list;
    }
}
