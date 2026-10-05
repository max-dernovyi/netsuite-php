<?php

namespace tests\Netsuite\Parity;

use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\CustomFieldRef;
use NetSuite\Classes\RecordRef;

/**
 * Turns a SOAP or REST response into a comparable array, dropping differences known to be harmless.
 */
class ResponseNormalizer
{
    const VOLATILE_DATES = ['createdDate', 'dateCreated', 'lastModifiedDate', 'lastModified'];

    /** @var string[] */
    private $volatileFields;

    /**
     * @param string[] $volatileFields properties dropped from every object
     */
    public function __construct(array $volatileFields = self::VOLATILE_DATES)
    {
        $this->volatileFields = array_flip($volatileFields);
    }

    /**
     * @param mixed $value
     * @param array<string, string> $placeholders exact scalar values to replace, e.g. ids created per transport
     * @return mixed
     */
    public function normalize($value, array $placeholders = [])
    {
        if (is_object($value)) {
            return $this->normalizeObject($value, $placeholders);
        }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) {
                $item = $this->normalize($item, $placeholders);
                if ($item !== null) {
                    $items[$key] = $item;
                }
            }
            if (array_values($value) === $value) {
                $items = array_values($items);
            }
            return $items ?: null;
        }
        if ((is_string($value) || is_int($value)) && array_key_exists((string) $value, $placeholders)) {
            return $placeholders[(string) $value];
        }
        return $value;
    }

    private function normalizeObject($object, array $placeholders): array
    {
        $fields = [];
        foreach (get_object_vars($object) as $name => $value) {
            if (isset($this->volatileFields[$name])
                || ($object instanceof CustomFieldRef && $name === 'internalId')
                || ($object instanceof RecordRef && $name === 'type')
            ) {
                continue;
            }
            $value = $this->normalize($value, $placeholders);
            if ($value !== null) {
                $fields[$name] = $value;
            }
        }
        if ($object instanceof CustomFieldList && isset($fields['customField'])) {
            usort($fields['customField'], function ($a, $b) {
                return strcmp((string) ($a['scriptId'] ?? ''), (string) ($b['scriptId'] ?? ''));
            });
        }
        ksort($fields);

        $class = get_class($object);
        return ['@class' => substr($class, strrpos($class, '\\') + 1)] + $fields;
    }
}
