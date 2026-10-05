<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\WriteResponse;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Mapping\RecordSerializer;
use NetSuite\Rest\Mapping\SerializedRecord;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Record\RecordClient;
use NetSuite\Rest\Response\ResponseBuilder;

abstract class AbstractWriteHandler implements OperationHandlerInterface
{
    /** @var RecordClient */
    protected $records;
    /** @var RecordRefs */
    protected $refs;
    /** @var RecordSerializer */
    protected $serializer;
    /** @var ResponseBuilder */
    protected $responses;

    public function __construct(RecordClient $records)
    {
        $this->records = $records;
        $this->refs = new RecordRefs();
        $this->serializer = new RecordSerializer();
        $this->responses = new ResponseBuilder();
    }

    /**
     * Writes one record, or a reference for delete; API errors come back as a failed status.
     *
     * @param mixed $item
     * @throws RestFault|NotSupportedOnRestException
     */
    public function write($item): WriteResponse
    {
        try {
            return $this->writeItem($item);
        } catch (RestError $error) {
            return $this->responses->writeFailure($error);
        }
    }

    /**
     * @param mixed $item
     * @throws RestError
     */
    abstract protected function writeItem($item): WriteResponse;

    /**
     * @param object $record
     * @throws RestError for a value that does not match its declared type or cannot be encoded
     */
    protected function serialize($record): SerializedRecord
    {
        try {
            $serialized = $this->serializer->serialize($record);
        } catch (\InvalidArgumentException $e) {
            throw RecordRefs::invalid($e->getMessage(), StatusDetailCodeType::INVALID_FLD_VALUE);
        }
        // Invalid UTF-8 or NAN/INF would fail later in RestClient, outside the per-item error handling.
        if (json_encode($serialized->body(), JSON_PRESERVE_ZERO_FRACTION) === false) {
            throw RecordRefs::invalid(
                'Record cannot be encoded as JSON: '.json_last_error_msg(),
                StatusDetailCodeType::INVALID_FLD_VALUE
            );
        }
        return $serialized;
    }

    /**
     * `RecordRef{internalId, externalId, type}`; custom records get a `CustomRecordRef`.
     *
     * @param object $target the written record or the deleted reference
     */
    protected function success($target, string $type, ?string $internalId): WriteResponse
    {
        $externalId = RecordRefs::property($target, 'externalId');
        if ($target instanceof CustomRecord || $target instanceof CustomRecordRef) {
            $ref = new CustomRecordRef();
            $ref->internalId = $internalId;
            $ref->externalId = $externalId;
            $ref->scriptId = $type;
            $ref->typeId = $target instanceof CustomRecordRef
                ? $target->typeId
                : ($target->recType instanceof RecordRef ? $target->recType->internalId : null);
        } else {
            $ref = $this->responses->recordRef($internalId, $externalId, $type);
        }
        return $this->responses->writeSuccess($ref);
    }
}
