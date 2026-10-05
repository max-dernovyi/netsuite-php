<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Record;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;
use NetSuite\Rest\Mapping\TypeMap;
use NetSuite\Rest\Record\RecordClient;
use NetSuite\Rest\Record\RecordTypeResolver;

/**
 * Turns records and references into REST types and ids; input SOAP would reject throws a RestError
 * before any request is sent.
 */
final class RecordRefs
{
    /** @var RecordTypeResolver */
    private $types;

    public function __construct()
    {
        $this->types = new RecordTypeResolver();
    }

    /**
     * @param mixed $ref
     * @throws RestError|NotSupportedOnRestException
     */
    public function refType($ref, string $operation): string
    {
        if (!$ref instanceof RecordRef && !$ref instanceof CustomRecordRef) {
            throw self::invalid(
                $operation.' needs a RecordRef or CustomRecordRef, got '.self::describe($ref),
                StatusDetailCodeType::INVALID_KEY_OR_REF
            );
        }
        $type = $this->resolve($ref);
        if ($ref instanceof RecordRef && !self::isRecordClass(TypeMap::CLASS_PREFIX.ucfirst($type))) {
            throw self::invalid('Record type "'.$type.'" is not a record', StatusDetailCodeType::INVALID_RCRD_TYPE);
        }
        return $type;
    }

    /**
     * @param mixed $record
     * @throws RestError|NotSupportedOnRestException
     */
    public function recordType($record, string $operation): string
    {
        if (!$record instanceof Record) {
            throw self::invalid(
                $operation.' needs a record, got '.self::describe($record),
                StatusDetailCodeType::INVALID_RCRD_TYPE
            );
        }
        return $this->resolve($record);
    }

    /**
     * The internal id, else `eid:{externalId}`.
     *
     * @param RecordRef|CustomRecordRef|Record $target
     * @throws RestError
     */
    public function id($target): string
    {
        $internalId = self::property($target, 'internalId');
        if ($internalId !== null) {
            return $internalId;
        }
        $externalId = self::property($target, 'externalId');
        if ($externalId !== null) {
            return RecordClient::externalId($externalId);
        }
        throw self::invalid('The reference has neither internalId nor externalId', StatusDetailCodeType::INVALID_KEY_OR_REF);
    }

    /**
     * @param object $target
     * @return string|null the property as a string, null when unset or empty
     */
    public static function property($target, string $name): ?string
    {
        if (!isset($target->$name) || $target->$name === '') {
            return null;
        }
        return (string) $target->$name;
    }

    public static function invalid(string $message, string $code): RestError
    {
        return new RestError(400, 'Bad Request', [new RestErrorDetail($message, $code)]);
    }

    private function resolve($target): string
    {
        try {
            return $this->types->resolve($target);
        } catch (\InvalidArgumentException $e) {
            throw self::invalid($e->getMessage(), StatusDetailCodeType::INVALID_RCRD_TYPE);
        }
    }

    private static function isRecordClass(string $class): bool
    {
        return class_exists($class) && is_subclass_of($class, Record::class);
    }

    private static function describe($value): string
    {
        return is_object($value) ? get_class($value) : gettype($value);
    }
}
