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
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\RecordRef;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Mapping\RecordHydrator;
use NetSuite\Rest\Mapping\TypeMap;
use NetSuite\Rest\Record\RecordClient;
use NetSuite\Rest\Response\ResponseBuilder;

/**
 * `get`: one record by `RecordRef` or `CustomRecordRef`, internal id first, then external id.
 */
final class GetHandler implements OperationHandlerInterface
{
    /** @var RecordClient */
    private $records;
    /** @var RecordRefs */
    private $refs;
    /** @var RecordHydrator */
    private $hydrator;
    /** @var ResponseBuilder */
    private $responses;

    public function __construct(RecordClient $records)
    {
        $this->records = $records;
        $this->refs = new RecordRefs();
        $this->hydrator = new RecordHydrator();
        $this->responses = new ResponseBuilder();
    }

    /**
     * @param GetRequest $request
     * @return GetResponse
     */
    public function handle($request)
    {
        $response = new GetResponse();
        $response->readResponse = $this->read($request->baseRef);
        return $response;
    }

    /**
     * @param mixed $ref
     * @throws RestFault|NotSupportedOnRestException
     */
    public function read($ref): ReadResponse
    {
        try {
            $type = $this->refs->refType($ref, 'get');
            $data = $this->records->get($type, $this->refs->id($ref));
        } catch (RestError $error) {
            return $this->responses->readFailure($error);
        }
        if (!$ref instanceof CustomRecordRef) {
            return $this->responses->readSuccess($this->hydrator->hydrate(TypeMap::CLASS_PREFIX.ucfirst($type), $data));
        }
        $record = $this->hydrator->hydrate(CustomRecord::class, $data);
        if ($record->recType === null && preg_match(RecordClient::INTERNAL_ID_PATTERN, (string) $ref->typeId)) {
            $record->recType = new RecordRef();
            $record->recType->internalId = (string) $ref->typeId;
        }
        return $this->responses->readSuccess($record);
    }
}
