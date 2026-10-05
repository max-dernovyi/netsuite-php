<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

use NetSuite\Classes\BaseRef;
use NetSuite\Classes\CustomFieldRef;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Rest\Exception\NotSupportedOnRestException;

/**
 * Generated record → REST JSON body, driven by each class's `$paramtypesmap` (merged up the class hierarchy).
 *
 * - null properties are omitted; the top-level `internalId` is left out (it goes into the path)
 * - references → `{"id"}` or `{"externalId"}`; enums → `{"id"}` with the value from EnumMapper
 * - `customFieldList` → top-level keys by script id; `nullFieldList` → explicit nulls
 * - list classes → `{"items": [...]}`; a top-level sublist with `replaceAll` true or unset (the SuiteTalk default)
 *   joins `replace`
 */
final class RecordSerializer
{
    const CLASS_PREFIX = 'NetSuite\\Classes\\';
    const ISO_8601 = 'Y-m-d\TH:i:sP';
    const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T/';

    /** @var FieldNameMap */
    private $names;
    /** @var EnumMapper */
    private $enums;

    /** @var array<string, array<string, string>> class → field → type */
    private static $types = [];
    /** @var array<string, array{0: string, 1: bool}|null> type → [item property, has replaceAll] */
    private static $lists = [];
    /** @var array<string, bool> */
    private static $enumTypes = [];

    public function __construct(?FieldNameMap $names = null, ?EnumMapper $enums = null)
    {
        $this->names = $names ?: new FieldNameMap();
        $this->enums = $enums ?: new EnumMapper();
    }

    /**
     * @param object $record a generated record
     * @throws \InvalidArgumentException for a value that does not match its declared type
     * @throws NotSupportedOnRestException for a custom field known only by its internal id
     */
    public function serialize($record): SerializedRecord
    {
        if (!is_object($record)) {
            throw new \InvalidArgumentException('Expected a record object, got '.gettype($record));
        }
        $replace = [];
        $body = $this->serializeObject($record, $replace);
        return new SerializedRecord($body, $replace);
    }

    /**
     * @param string[]|null $replace collects replaced sublists; null below the top level
     */
    private function serializeObject($object, ?array &$replace = null): array
    {
        $class = get_class($object);
        $shortClass = substr($class, strrpos('\\'.$class, '\\'));
        $body = [];
        $nulls = null;
        foreach ($this->types($class) as $field => $type) {
            if (!isset($object->$field) || ($replace !== null && $field === 'internalId')) {
                continue;
            }
            $value = $object->$field;
            $path = $shortClass.'.'.$field;
            if ($type === 'NullField') {
                $nulls = $value;
            } elseif ($type === 'CustomFieldList') {
                $body = array_merge($body, $this->customFields($value, $path));
            } elseif ($this->listShape($type) !== null) {
                $name = $this->names->toRest($class, $field, $this->listShape($type)[1]);
                $body[$name] = $this->serializeList($type, $value, $path);
                if ($replace !== null && $this->listShape($type)[1] && ($value->replaceAll === null
                    || filter_var($value->replaceAll, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true)) {
                    $replace[] = $name;
                }
            } else {
                $converted = $this->value($type, $value, $path);
                if ($converted !== null) {
                    $body[$this->names->toRest($class, $field)] = $converted;
                }
            }
        }
        if (is_object($nulls) && isset($nulls->name)) {
            foreach ($this->wrap($nulls->name) as $field) {
                $type = isset($this->types($class)[$field]) ? $this->types($class)[$field] : null;
                $isSublist = $type !== null && $this->listShape($type) !== null && $this->listShape($type)[1];
                $body[$this->names->toRest($class, (string) $field, $isSublist)] = null;
            }
        }
        return $body;
    }

    /**
     * @return mixed|null null when the value serializes to nothing
     */
    private function value(string $type, $value, string $path)
    {
        if (substr($type, -2) === '[]') {
            $items = [];
            foreach ($this->wrap($value) as $i => $item) {
                $converted = $item === null ? null : $this->value(substr($type, 0, -2), $item, $path.'['.$i.']');
                if ($converted !== null) {
                    $items[] = $converted;
                }
            }
            return $items;
        }
        switch ($type) {
            case 'string':
                return is_scalar($value) ? (string) $value : $this->mismatch($type, $value, $path);
            case 'boolean':
                $bool = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                return $bool !== null ? $bool : $this->mismatch($type, $value, $path);
            case 'integer':
                $int = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT);
                return $int !== false ? $int : $this->mismatch($type, $value, $path);
            case 'float':
                return is_numeric($value) ? (float) $value : $this->mismatch($type, $value, $path);
            case 'dateTime':
                return $this->dateTime($value, $path);
        }
        if ($value instanceof BaseRef || $value instanceof ListOrRecordRef) {
            return $this->ref($value);
        }
        if (is_object($value) && strpos(get_class($value), self::CLASS_PREFIX) === 0) {
            $body = $this->serializeObject($value);
            return $body === [] ? null : $body;
        }
        if (is_string($value) && $this->isEnum($type)) {
            return ['id' => $this->enums->toRest($type, $value)];
        }
        return $this->mismatch($type, $value, $path);
    }

    private function serializeList(string $type, $list, string $path): array
    {
        if (!is_object($list)) {
            return $this->mismatch($type, $list, $path);
        }
        $property = $this->listShape($type)[0];
        $itemType = $this->types(self::CLASS_PREFIX.$type)[$property];
        return ['items' => isset($list->$property) ? $this->value($itemType, $list->$property, $path) : []];
    }

    private function customFields($list, string $path): array
    {
        $body = [];
        foreach ($this->wrap(is_object($list) && isset($list->customField) ? $list->customField : []) as $field) {
            if (!$field instanceof CustomFieldRef) {
                $this->mismatch('CustomFieldRef', $field, $path);
            }
            $id = $this->customFieldId($field);
            $types = $this->types(get_class($field));
            if (!isset($field->value, $types['value'])) {
                continue;
            }
            $value = $this->value($types['value'], $field->value, $path.'.'.$id);
            if ($value !== null) {
                $body[$id] = substr($types['value'], -2) === '[]' ? ['items' => $value] : $value;
            }
        }
        return $body;
    }

    private function customFieldId(CustomFieldRef $field): string
    {
        foreach ([$field->scriptId, $field->internalId] as $id) {
            if ($id !== null && $id !== '' && !ctype_digit(ltrim((string) $id, '-'))) {
                return (string) $id;
            }
        }
        if ($field->internalId !== null && $field->internalId !== '') {
            throw new NotSupportedOnRestException(
                'Custom field "'.$field->internalId.'" is referenced by internal id; REST needs its script id'
            );
        }
        throw new \InvalidArgumentException('Custom field has no script id');
    }

    /**
     * @param BaseRef|ListOrRecordRef $ref
     */
    private function ref($ref): ?array
    {
        if (isset($ref->internalId) && $ref->internalId !== '') {
            return ['id' => (string) $ref->internalId];
        }
        if (isset($ref->externalId) && $ref->externalId !== '') {
            return ['externalId' => (string) $ref->externalId];
        }
        return null;
    }

    private function dateTime($value, string $path): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(self::ISO_8601);
        }
        if (is_string($value) && preg_match(self::DATE_PATTERN, $value)) {
            return $value;
        }
        if (is_string($value) && preg_match(self::DATE_TIME_PATTERN, $value)) {
            try {
                return (new \DateTimeImmutable($value))->format(self::ISO_8601);
            } catch (\Exception $e) {
                // falls through to the mismatch below
            }
        }
        return $this->mismatch('dateTime', $value, $path);
    }

    /**
     * @return array<string, string>
     */
    private function types(string $class): array
    {
        if (!isset(self::$types[$class])) {
            $map = [];
            for ($c = $class; $c !== false; $c = get_parent_class($c)) {
                if (property_exists($c, 'paramtypesmap')) {
                    $map += $c::$paramtypesmap;
                }
            }
            self::$types[$class] = $map;
        }
        return self::$types[$class];
    }

    /**
     * A list class has one array property, optionally next to `replaceAll`.
     *
     * @return array{0: string, 1: bool}|null [item property, has replaceAll]
     */
    private function listShape(string $type): ?array
    {
        if (!array_key_exists($type, self::$lists)) {
            self::$lists[$type] = null;
            $class = self::CLASS_PREFIX.$type;
            if (!in_array($type, ['CustomFieldList', 'NullField'], true) && class_exists($class)) {
                $types = $this->types($class);
                $arrays = array_keys(array_filter($types, function ($t) {
                    return substr($t, -2) === '[]';
                }));
                $others = array_diff(array_keys($types), $arrays);
                if (count($arrays) === 1 && array_diff($others, ['replaceAll']) === []) {
                    self::$lists[$type] = [$arrays[0], $others !== []];
                }
            }
        }
        return self::$lists[$type];
    }

    private function isEnum(string $type): bool
    {
        if (!isset(self::$enumTypes[$type])) {
            $class = self::CLASS_PREFIX.$type;
            self::$enumTypes[$type] = class_exists($class) && $this->types($class) === []
                && (new \ReflectionClass($class))->getConstants() !== [];
        }
        return self::$enumTypes[$type];
    }

    private function wrap($value): array
    {
        return is_array($value) ? $value : [$value];
    }

    /**
     * @return mixed never returns
     */
    private function mismatch(string $type, $value, string $path)
    {
        throw new \InvalidArgumentException(sprintf(
            '%s expects %s, got %s',
            $path,
            $type,
            is_object($value) ? get_class($value) : gettype($value)
        ));
    }
}
