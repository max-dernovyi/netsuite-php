<?php

namespace tests\Netsuite\Rest\Mapping;

use NetSuite\Classes\Country;
use NetSuite\Classes\SalesOrderOrderStatus;
use NetSuite\Rest\Mapping\EnumMapper;
use PHPUnit\Framework\TestCase;

class EnumMapperTest extends TestCase
{
    public function testEveryCountryIsMappedToAUniqueCode()
    {
        $constants = (new \ReflectionClass(Country::class))->getConstants();
        $this->assertCount(count($constants), EnumMapper::COUNTRY);
        $this->assertCount(count($constants), array_unique(EnumMapper::COUNTRY));

        $mapper = new EnumMapper();
        foreach ($constants as $value) {
            $this->assertArrayHasKey($value, EnumMapper::COUNTRY);
            $this->assertMatchesPattern('/^[A-Z]{2}$/', $mapper->toRest('Country', $value));
        }
    }

    public function testCountryCodes()
    {
        $mapper = new EnumMapper();
        $this->assertSame('US', $mapper->toRest('Country', Country::_unitedStates));
        $this->assertSame('GB', $mapper->toRest('Country', Country::_unitedKingdom));
        $this->assertSame('DE', $mapper->toRest(Country::class, Country::_germany));
        $this->assertSame('XK', $mapper->toRest('Country', Country::_kosovo));
    }

    public function testSalesOrderStatus()
    {
        $mapper = new EnumMapper();
        $expected = [
            SalesOrderOrderStatus::_pendingApproval => 'A',
            SalesOrderOrderStatus::_pendingFulfillment => 'B',
            SalesOrderOrderStatus::_cancelled => 'C',
            SalesOrderOrderStatus::_partiallyFulfilled => 'D',
            SalesOrderOrderStatus::_pendingBillingPartFulfilled => 'E',
            SalesOrderOrderStatus::_pendingBilling => 'F',
            SalesOrderOrderStatus::_fullyBilled => 'G',
            SalesOrderOrderStatus::_closed => 'H',
        ];
        foreach ($expected as $value => $code) {
            $this->assertSame($code, $mapper->toRest('SalesOrderOrderStatus', $value));
        }
    }

    public function testUnknownValuesPassThrough()
    {
        $mapper = new EnumMapper();
        $this->assertSame('_atlantis', $mapper->toRest('Country', '_atlantis'));
        $this->assertSame('_undefined', $mapper->toRest('SalesOrderOrderStatus', SalesOrderOrderStatus::_undefined));
        $this->assertSame('_english', $mapper->toRest('Language', '_english'));
    }

    private function assertMatchesPattern(string $pattern, string $value)
    {
        $this->assertSame(1, preg_match($pattern, $value), $value);
    }
}
