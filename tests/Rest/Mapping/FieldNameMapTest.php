<?php

namespace tests\Netsuite\Rest\Mapping;

use NetSuite\Classes\Customer;
use NetSuite\Classes\Task;
use NetSuite\Rest\Mapping\FieldNameMap;
use PHPUnit\Framework\TestCase;

class FieldNameMapTest extends TestCase
{
    public function testGlobalRenames()
    {
        $map = new FieldNameMap();
        $this->assertSame('addressBook', $map->toRest('Customer', 'addressbookList', true));
        $this->assertSame('addressBookAddress', $map->toRest('CustomerAddressbook', 'addressbookAddress'));
    }

    public function testSublistSuffixIsStripped()
    {
        $map = new FieldNameMap();
        $this->assertSame('item', $map->toRest('SalesOrder', 'itemList', true));
        $this->assertSame('salesTeam', $map->toRest('Customer', 'salesTeamList', true));
    }

    public function testOtherNamesAreKept()
    {
        $map = new FieldNameMap();
        $this->assertSame('subsidiaryList', $map->toRest('Account', 'subsidiaryList'));
        $this->assertSame('entityId', $map->toRest('Customer', 'entityId'));
        $this->assertSame('List', $map->toRest('Customer', 'List', true));
    }

    public function testPerClassOverrides()
    {
        $map = new FieldNameMap(['customer' => ['currencyList' => 'currencies', 'addressbookList' => 'addresses']]);
        $this->assertSame('currencies', $map->toRest(Customer::class, 'currencyList', true));
        $this->assertSame('addresses', $map->toRest('Customer', 'addressbookList', true));
        $this->assertSame('currencyList', $map->toRest('Vendor', 'currencyList', true));
    }

    public function testSuffixIsKeptWhenTheStrippedNameIsAnotherField()
    {
        $map = new FieldNameMap();
        $this->assertSame('currencyList', $map->toRest('Customer', 'currencyList', true));
        $this->assertSame('contactList', $map->toRest(Task::class, 'contactList', true));
        $this->assertSame('directDepositList', $map->toRest('Employee', 'directDepositList', true));
        $this->assertSame('item', $map->toRest('NoSuchClass', 'itemList', true));
    }
}
