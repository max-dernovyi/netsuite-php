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
use NetSuite\Classes\Record;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Mapping\RecordHydrator;
use NetSuite\Rest\Mapping\TypeMap;
use NetSuite\Rest\Record\RecordClient;
use NetSuite\Rest\Record\RecordTypeResolver;
use NetSuite\Rest\Response\ResponseBuilder;

/**
 * `get`: one record by `RecordRef` or `CustomRecordRef`, internal id first, then external id.
 */
final class GetHandler implements OperationHandlerInterface
{
    /** @var RecordClient */
    private $records;
    /** @var RecordTypeResolver */
    private $types;
    /** @var RecordHydrator */
    private $hydrator;
    /** @var ResponseBuilder */
    private $responses;

    public function __construct(
        RecordClient $records,
        ?RecordTypeResolver $types = null,
        ?RecordHydrator $hydrator = null,
        ?ResponseBuilder $responses = null
    ) {
        $this->records = $records;
        $this->types = $types ?: new RecordTypeResolver();
        $this->hydrator = $hydrator ?: new RecordHydrator();
        $this->responses = $responses ?: new ResponseBuilder();
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
            $type = $this->type($ref);
            $data = $this->records->get($type, $this->id($ref));
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

    /**
     * @throws RestError for a reference SOAP would reject
     */
    private function type($ref): string
    {
        if (!$ref instanceof RecordRef && !$ref instanceof CustomRecordRef) {
            throw $this->invalid(
                'get needs a RecordRef or CustomRecordRef, got '.(is_object($ref) ? get_class($ref) : gettype($ref)),
                StatusDetailCodeType::INVALID_KEY_OR_REF
            );
        }
        try {
            $type = $this->types->resolve($ref);
        } catch (\InvalidArgumentException $e) {
            throw $this->invalid($e->getMessage(), StatusDetailCodeType::INVALID_RCRD_TYPE);
        }
        if ($ref instanceof RecordRef && !$this->isRecordClass(TypeMap::CLASS_PREFIX.ucfirst($type))) {
            throw $this->invalid('Record type "'.$type.'" cannot be read', StatusDetailCodeType::INVALID_RCRD_TYPE);
        }
        return $type;
    }

    /**
     * @param RecordRef|CustomRecordRef $ref
     */
    private function id($ref): string
    {
        if ($ref->internalId !== null && $ref->internalId !== '') {
            return (string) $ref->internalId;
        }
        if ($ref->externalId !== null && $ref->externalId !== '') {
            return RecordClient::externalId((string) $ref->externalId);
        }
        throw $this->invalid('The reference has neither internalId nor externalId', StatusDetailCodeType::INVALID_KEY_OR_REF);
    }

    private function isRecordClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Record::class);
    }

    private function invalid(string $message, string $code): RestError
    {
        return new RestError(400, 'Bad Request', [new RestErrorDetail($message, $code)]);
    }
}
