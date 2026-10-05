<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Record;

use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Record;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Rest\Exception\NotSupportedOnRestException;

/**
 * Maps a generated record (object or class name), a RecordRef, a CustomRecordRef or a RecordType value
 * to the REST record type name. Custom records resolve to their type's script id (`customrecord_*`).
 */
final class RecordTypeResolver
{
    const CLASS_PREFIX = 'NetSuite\\Classes\\';
    const CUSTOM_RECORD_PATTERN = '/^customrecord_*[a-z0-9]\w*$/i';

    /** @var array<string, string>|null lower-case RecordType value → canonical value */
    private static $types;

    /**
     * @param Record|RecordRef|CustomRecordRef|string $target
     * @throws NotSupportedOnRestException for a custom record type known only by its internal id
     * @throws \InvalidArgumentException for anything that is not a top-level record type
     */
    public function resolve($target): string
    {
        if ($target instanceof CustomRecordRef) {
            return $this->customRecordType($target->scriptId, $target->typeId);
        }
        if ($target instanceof CustomRecord) {
            $typeId = $target->recType instanceof RecordRef ? $target->recType->internalId : null;
            return $this->customRecordType(null, $typeId);
        }
        if ($target instanceof RecordRef) {
            if ($target->type === null || $target->type === '') {
                throw new \InvalidArgumentException('RecordRef has no type');
            }
            return $this->fromString((string) $target->type);
        }
        if ($target instanceof Record) {
            return $this->fromClass(get_class($target));
        }
        if (is_string($target) && $target !== '') {
            if (strpos(ltrim($target, '\\'), self::CLASS_PREFIX) === 0) {
                return $this->fromClass(ltrim($target, '\\'));
            }
            return $this->fromString($target);
        }
        throw new \InvalidArgumentException(
            'Cannot resolve a REST record type from '.(is_object($target) ? get_class($target) : gettype($target))
        );
    }

    private function fromClass(string $class): string
    {
        if (!is_a($class, Record::class, true)) {
            throw new \InvalidArgumentException('Not a NetSuite record class: '.$class);
        }
        if (is_a($class, CustomRecord::class, true)) {
            throw new NotSupportedOnRestException('A CustomRecord type needs its script id (customrecord_*)');
        }
        $type = $this->knownType(lcfirst(substr($class, strlen(self::CLASS_PREFIX))));
        if ($type === null) {
            throw new \InvalidArgumentException('Not a top-level record type: '.$class);
        }
        return $type;
    }

    private function fromString(string $value): string
    {
        $type = $this->knownType($value);
        if ($type === RecordType::customRecord) {
            throw new NotSupportedOnRestException('A customRecord type needs its script id (customrecord_*)');
        }
        if ($type !== null) {
            return $type;
        }
        if (preg_match(self::CUSTOM_RECORD_PATTERN, $value)) {
            return strtolower($value);
        }
        throw new \InvalidArgumentException('Unknown record type: "'.$value.'"');
    }

    private function customRecordType(?string $scriptId, ?string $typeId): string
    {
        foreach ([$scriptId, $typeId] as $candidate) {
            if ($candidate !== null && preg_match(self::CUSTOM_RECORD_PATTERN, $candidate)) {
                return strtolower($candidate);
            }
        }
        if ($typeId !== null && $typeId !== '') {
            throw new NotSupportedOnRestException(
                'Custom record type "'.$typeId.'" is referenced by internal id; REST needs its script id (customrecord_*)'
            );
        }
        throw new \InvalidArgumentException('Custom record reference has no type');
    }

    private function knownType(string $value): ?string
    {
        if (self::$types === null) {
            self::$types = [];
            foreach ((new \ReflectionClass(RecordType::class))->getConstants() as $type) {
                self::$types[strtolower($type)] = $type;
            }
        }
        $key = strtolower($value);
        return isset(self::$types[$key]) ? self::$types[$key] : null;
    }
}
