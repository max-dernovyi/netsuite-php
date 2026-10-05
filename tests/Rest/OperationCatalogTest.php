<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest;

use NetSuite\NetSuiteService;
use NetSuite\Rest\OperationCatalog;
use PHPUnit\Framework\TestCase;

class OperationCatalogTest extends TestCase
{
    public function testCoversEveryServiceOperation()
    {
        $operations = self::serviceOperations();

        $this->assertCount(42, $operations);
        $this->assertEqualsCanonicalizing($operations, array_keys(OperationCatalog::STATUSES));
    }

    public function testStatus()
    {
        $this->assertSame('planned, SuiteQL', OperationCatalog::status('search'));
        $this->assertSame('no REST equivalent', OperationCatalog::status('changePassword'));
        $this->assertSame('unknown operation', OperationCatalog::status('login'));
    }

    /**
     * @return string[] the operations NetSuiteService itself declares
     */
    public static function serviceOperations(): array
    {
        $operations = [];
        foreach ((new \ReflectionClass(NetSuiteService::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === NetSuiteService::class) {
                $operations[] = $method->getName();
            }
        }
        return $operations;
    }
}
