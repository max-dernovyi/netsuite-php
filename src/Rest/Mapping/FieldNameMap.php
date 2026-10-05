<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Mapping;

/**
 * SOAP → REST field names. Lookup order: per-class override, global rename, `List` suffix stripped from sublists.
 */
final class FieldNameMap
{
    const GLOBAL_RENAMES = [
        'addressbookList' => 'addressBook',
        'addressbookAddress' => 'addressBookAddress',
    ];

    /** @var array<string, array<string, string>> lower-case short class name → SOAP name → REST name */
    private $overrides = [];

    /**
     * @param array<string, array<string, string>> $overrides short class name (e.g. `Customer`) → SOAP name → REST name
     */
    public function __construct(array $overrides = [])
    {
        foreach ($overrides as $class => $names) {
            $this->overrides[strtolower($class)] = $names;
        }
    }

    /**
     * @param string $class the generated class holding the field (short or fully qualified name)
     */
    public function toRest(string $class, string $soapName, bool $isSublist = false): string
    {
        $key = strtolower(substr($class, (int) strrpos('\\'.$class, '\\')));
        if (isset($this->overrides[$key][$soapName])) {
            return $this->overrides[$key][$soapName];
        }
        if (isset(self::GLOBAL_RENAMES[$soapName])) {
            return self::GLOBAL_RENAMES[$soapName];
        }
        if ($isSublist && strlen($soapName) > 4 && substr($soapName, -4) === 'List') {
            return substr($soapName, 0, -4);
        }
        return $soapName;
    }
}
