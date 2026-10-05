<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

use NetSuite\Classes\BaseRef;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\CustomFieldRef;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\DoubleCustomFieldRef;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\LongCustomFieldRef;
use NetSuite\Classes\MultiSelectCustomFieldRef;
use NetSuite\Classes\SelectCustomFieldRef;
use NetSuite\Classes\StringCustomFieldRef;

/**
 * REST JSON (decoded as arrays) → generated record, the inverse of RecordSerializer.
 *
 * - keys match REST and SOAP field names case-insensitively; `id` → `internalId`, `refName` → `name` on references
 * - `{"items": [...]}` → list classes and arrays; `links`, unknown keys and values of the wrong shape are dropped
 * - unmatched `cust*` keys → `customFieldList`, the `*CustomFieldRef` subclass chosen from the JSON value
 *   (a whole-number double arrives as an int and becomes a `LongCustomFieldRef`)
 * - enum values are mapped back through EnumMapper; date-times → ISO 8601 with offset, dates kept as is
 */
final class RecordHydrator
{
    const CUSTOM_FIELD_PREFIX = 'cust';

    /** @var FieldNameMap */
    private $names;
    /** @var EnumMapper */
    private $enums;
    /** @var array<string, array<string, string>> class → lower-case JSON key → field */
    private $keys = [];

    public function __construct(?FieldNameMap $names = null, ?EnumMapper $enums = null)
    {
        $this->names = $names ?: new FieldNameMap();
        $this->enums = $enums ?: new EnumMapper();
    }

    /**
     * @param string $class a generated record class (short or fully qualified name)
     * @param array<string, mixed> $data the decoded REST record
     * @return object
     */
    public function hydrate(string $class, array $data)
    {
        $class = strpos($class, '\\') === false ? TypeMap::CLASS_PREFIX.$class : ltrim($class, '\\');
        if (!class_exists($class) || TypeMap::fields($class) === []) {
            throw new \InvalidArgumentException('Not a generated record class: '.$class);
        }
        return $this->hydrateObject($class, $data);
    }

    private function hydrateObject(string $class, array $data)
    {
        $object = new $class();
        $types = TypeMap::fields($class);
        $customFields = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $field = $this->field($class, (string) $key);
            if ($field !== null) {
                $converted = $this->value($types[$field], $value);
                if ($converted !== null) {
                    $object->$field = $converted;
                }
            } elseif (isset($types['customFieldList']) && stripos((string) $key, self::CUSTOM_FIELD_PREFIX) === 0) {
                $customField = $this->customField((string) $key, $value);
                if ($customField !== null) {
                    $customFields[] = $customField;
                }
            }
        }
        if ($customFields !== []) {
            $object->customFieldList = new CustomFieldList();
            $object->customFieldList->customField = $customFields;
        }
        return $object;
    }

    /**
     * @return mixed|null null when the value does not fit the type
     */
    private function value(string $type, $value)
    {
        if (substr($type, -2) === '[]') {
            return $this->values(substr($type, 0, -2), $value);
        }
        switch ($type) {
            case 'string':
                if (is_bool($value)) {
                    return $value ? 'true' : 'false';
                }
                if (is_array($value) && isset($value['id']) && is_scalar($value['id'])) {
                    return (string) $value['id'];
                }
                return is_scalar($value) ? (string) $value : null;
            case 'boolean':
                return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            case 'integer':
                $int = is_bool($value) || is_array($value) ? false : filter_var($value, FILTER_VALIDATE_INT);
                return $int !== false ? $int : null;
            case 'float':
                return is_numeric($value) ? (float) $value : null;
            case 'dateTime':
                return is_string($value) ? $this->dateTime($value) : null;
        }
        if (TypeMap::isEnum($type)) {
            if (is_array($value)) {
                $value = isset($value['id']) ? $value['id'] : null;
            }
            return is_scalar($value) ? $this->enums->fromRest($type, (string) $value) : null;
        }
        $class = TypeMap::CLASS_PREFIX.$type;
        if (TypeMap::listShape($type) !== null) {
            return $this->hydrateList($class, TypeMap::listShape($type)[0], $value);
        }
        if (!class_exists($class)) {
            return null;
        }
        if (is_scalar($value) && !is_bool($value) && $this->isRef($class) && property_exists($class, 'internalId')) {
            $ref = new $class();
            $ref->internalId = (string) $value;
            return $ref;
        }
        if (!is_array($value) || $this->isList($value)) {
            return null;
        }
        $object = $this->hydrateObject($class, $value);
        return array_filter(get_object_vars($object), function ($v) {
            return $v !== null;
        }) === [] ? null : $object;
    }

    /**
     * @return array|null `{"items": [...]}`, a JSON array or a single item → typed items; null when none survive
     */
    private function values(string $itemType, $value): ?array
    {
        if (is_array($value) && isset($value['items']) && is_array($value['items'])) {
            $value = $value['items'];
        }
        if (!is_array($value) || !$this->isList($value)) {
            $value = [$value];
        }
        $items = [];
        foreach ($value as $item) {
            $converted = $item === null ? null : $this->value($itemType, $item);
            if ($converted !== null) {
                $items[] = $converted;
            }
        }
        return $items === [] ? null : $items;
    }

    private function hydrateList(string $class, string $property, $value)
    {
        if (!is_array($value)) {
            return null;
        }
        $items = $this->value(TypeMap::fields($class)[$property], $value);
        if ($items === null) {
            return null;
        }
        $list = new $class();
        $list->$property = $items;
        return $list;
    }

    private function customField(string $scriptId, $value): ?CustomFieldRef
    {
        if (is_bool($value)) {
            $field = new BooleanCustomFieldRef();
        } elseif (is_int($value)) {
            $field = new LongCustomFieldRef();
        } elseif (is_float($value)) {
            $field = new DoubleCustomFieldRef();
        } elseif (is_string($value)) {
            $isDate = preg_match(RecordSerializer::DATE_PATTERN, $value)
                || preg_match(RecordSerializer::DATE_TIME_PATTERN, $value);
            $field = $isDate ? new DateCustomFieldRef() : new StringCustomFieldRef();
            $value = $isDate ? $this->dateTime($value) : $value;
        } elseif (is_array($value) && !isset($value['items']) && !$this->isList($value)) {
            $field = new SelectCustomFieldRef();
            $value = $this->value('ListOrRecordRef', $value);
        } elseif (is_array($value)) {
            $field = new MultiSelectCustomFieldRef();
            $value = $this->values('ListOrRecordRef', $value);
        } else {
            return null;
        }
        if ($value === null) {
            return null;
        }
        $field->scriptId = $scriptId;
        $field->value = $value;
        return $field;
    }

    /**
     * REST names first, then SOAP names, then the `id` and `refName` aliases.
     */
    private function field(string $class, string $key): ?string
    {
        if (!isset($this->keys[$class])) {
            $types = TypeMap::fields($class);
            $index = [];
            foreach ($types as $field => $type) {
                $shape = TypeMap::listShape($type);
                $index[strtolower($this->names->toRest($class, $field, $shape !== null && $shape[1]))] = $field;
            }
            foreach (array_keys($types) as $field) {
                $index += [strtolower($field) => $field];
            }
            if (isset($types['internalId'])) {
                $index += ['id' => 'internalId'];
            }
            if (isset($types['name']) && $this->isRef($class)) {
                $index += ['refname' => 'name'];
            }
            $this->keys[$class] = $index;
        }
        $key = strtolower($key);
        return isset($this->keys[$class][$key]) ? $this->keys[$class][$key] : null;
    }

    private function dateTime(string $value): string
    {
        if (!preg_match(RecordSerializer::DATE_TIME_PATTERN, $value)) {
            return $value;
        }
        try {
            return (new \DateTimeImmutable($value))->format(RecordSerializer::ISO_8601);
        } catch (\Exception $e) {
            return $value;
        }
    }

    private function isRef(string $class): bool
    {
        return is_a($class, BaseRef::class, true) || is_a($class, ListOrRecordRef::class, true);
    }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
