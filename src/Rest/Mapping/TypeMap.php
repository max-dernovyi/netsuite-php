<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

/**
 * Cached `$paramtypesmap` lookups for the generated classes.
 */
final class TypeMap
{
    const CLASS_PREFIX = 'NetSuite\\Classes\\';

    /** @var array<string, array<string, string>> class → field → type */
    private static $fields = [];
    /** @var array<string, array{0: string, 1: bool}|null> type → [item property, has replaceAll] */
    private static $lists = [];
    /** @var array<string, bool> */
    private static $enums = [];

    /**
     * Field types merged up the class hierarchy.
     *
     * @return array<string, string>
     */
    public static function fields(string $class): array
    {
        if (!isset(self::$fields[$class])) {
            $map = [];
            for ($c = $class; $c !== false; $c = get_parent_class($c)) {
                if (property_exists($c, 'paramtypesmap')) {
                    $map += $c::$paramtypesmap;
                }
            }
            self::$fields[$class] = $map;
        }
        return self::$fields[$class];
    }

    /**
     * A list class has one array property, optionally next to `replaceAll`.
     *
     * @return array{0: string, 1: bool}|null [item property, has replaceAll]
     */
    public static function listShape(string $type): ?array
    {
        if (!array_key_exists($type, self::$lists)) {
            self::$lists[$type] = null;
            $class = self::CLASS_PREFIX.$type;
            if (!in_array($type, ['CustomFieldList', 'NullField'], true) && class_exists($class)) {
                $types = self::fields($class);
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

    public static function isEnum(string $type): bool
    {
        if (!isset(self::$enums[$type])) {
            $class = self::CLASS_PREFIX.$type;
            self::$enums[$type] = class_exists($class) && self::fields($class) === []
                && (new \ReflectionClass($class))->getConstants() !== [];
        }
        return self::$enums[$type];
    }
}
