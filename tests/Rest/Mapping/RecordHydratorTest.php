<?php

namespace tests\Netsuite\Rest\Mapping;

use NetSuite\Classes\Address;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\Country;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\CustomerCurrencyList;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\DoubleCustomFieldRef;
use NetSuite\Classes\EmailPreference;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\LongCustomFieldRef;
use NetSuite\Classes\MultiSelectCustomFieldRef;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\SalesOrderOrderStatus;
use NetSuite\Classes\SelectCustomFieldRef;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Rest\Mapping\FieldNameMap;
use NetSuite\Rest\Mapping\RecordHydrator;
use NetSuite\Rest\Mapping\RecordSerializer;
use PHPUnit\Framework\TestCase;

class RecordHydratorTest extends TestCase
{
    /** @var RecordHydrator */
    private $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new RecordHydrator();
    }

    public function testCustomerFixtureBodyFields()
    {
        $customer = $this->hydrator->hydrate('customer', $this->fixture('customer'));

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertSame('107', $customer->internalId);
        $this->assertSame('CUST_107', $customer->externalId);
        $this->assertSame('Acme Inc.', $customer->entityId);
        $this->assertFalse($customer->isPerson);
        $this->assertFalse($customer->isInactive);
        $this->assertSame(5000.0, $customer->creditLimit);
        $this->assertSame(1250.5, $customer->balance);
        $this->assertSame(3, $customer->daysOverdue);
        $this->assertSame('2026-01-02T17:15:30+00:00', $customer->dateCreated);
        $this->assertSame(EmailPreference::_pDF, $customer->emailPreference);
        $this->assertEquals($this->ref('1', 'Honeycomb Mfg.'), $customer->subsidiary);
        $this->assertEquals($this->ref('-2', 'Standard Customer Form'), $customer->customForm);
        $this->assertEquals($this->ref('1', 'USA'), $customer->currency);
    }

    public function testCustomerFixtureSublistsAndAddressSubrecord()
    {
        $customer = $this->hydrator->hydrate('Customer', $this->fixture('customer'));

        $this->assertInstanceOf(CustomerAddressbookList::class, $customer->addressbookList);
        $this->assertNull($customer->addressbookList->replaceAll);
        $this->assertCount(1, $customer->addressbookList->addressbook);
        $line = $customer->addressbookList->addressbook[0];
        $this->assertInstanceOf(CustomerAddressbook::class, $line);
        $this->assertSame('9', $line->internalId);
        $this->assertTrue($line->defaultBilling);
        $this->assertFalse($line->isResidential);
        $this->assertSame('HQ', $line->label);

        $address = $line->addressbookAddress;
        $this->assertInstanceOf(Address::class, $address);
        $this->assertSame(Country::_unitedStates, $address->country);
        $this->assertSame('2955 Campus Drive', $address->addr1);
        $this->assertSame('94403', $address->zip);
        $this->assertFalse($address->override);

        $this->assertInstanceOf(CustomerCurrencyList::class, $customer->currencyList);
        $this->assertEquals($this->ref('1', 'USA'), $customer->currencyList->currency[0]->currency);
        $this->assertSame(0.0, $customer->currencyList->currency[0]->overdueBalance);
    }

    public function testEveryCustomFieldShape()
    {
        $customer = $this->hydrator->hydrate('customer', $this->fixture('customer'));

        $this->assertInstanceOf(CustomFieldList::class, $customer->customFieldList);
        $fields = [];
        foreach ($customer->customFieldList->customField as $field) {
            $this->assertNull($field->internalId);
            $fields[$field->scriptId] = $field;
        }
        $this->assertSame(
            ['custentity_flag', 'custentity_count', 'custentity_ratio', 'custentity_since', 'custentity_text',
                'custentity_tier', 'custentity_tags'],
            array_keys($fields)
        );
        $this->assertCustomField(BooleanCustomFieldRef::class, true, $fields['custentity_flag']);
        $this->assertCustomField(LongCustomFieldRef::class, 7, $fields['custentity_count']);
        $this->assertCustomField(DoubleCustomFieldRef::class, 0.5, $fields['custentity_ratio']);
        $this->assertCustomField(DateCustomFieldRef::class, '2026-03-04', $fields['custentity_since']);
        $this->assertCustomField(StringCustomFieldRef::class, 'hello', $fields['custentity_text']);
        $this->assertInstanceOf(SelectCustomFieldRef::class, $fields['custentity_tier']);
        $this->assertEquals($this->listRef('3', 'Gold'), $fields['custentity_tier']->value);
        $this->assertInstanceOf(MultiSelectCustomFieldRef::class, $fields['custentity_tags']);
        $this->assertEquals(
            [$this->listRef('1', 'Retail'), $this->listRef('2', 'Wholesale')],
            $fields['custentity_tags']->value
        );
    }

    public function testCustomFieldEdgeShapes()
    {
        $customer = $this->hydrator->hydrate('customer', [
            'custentity_when' => '2026-03-04T10:00:00Z',
            'custentity_odd' => '2026-13-45Tnot a date',
            'custentity_list' => [['id' => '5']],
            'custentity_unset' => ['links' => []],
            'custentity_empty' => ['items' => []],
        ]);

        $fields = $customer->customFieldList->customField;
        $this->assertCount(3, $fields);
        $this->assertCustomField(DateCustomFieldRef::class, '2026-03-04T10:00:00+00:00', $fields[0]);
        $this->assertCustomField(DateCustomFieldRef::class, '2026-13-45Tnot a date', $fields[1]);
        $this->assertInstanceOf(MultiSelectCustomFieldRef::class, $fields[2]);
        $this->assertEquals([$this->listRef('5', null)], $fields[2]->value);
    }

    public function testSalesOrderFixture()
    {
        $order = $this->hydrator->hydrate('salesOrder', $this->fixture('salesOrder'));

        $this->assertInstanceOf(SalesOrder::class, $order);
        $this->assertSame('5012', $order->internalId);
        $this->assertSame('SO1042', $order->tranId);
        $this->assertSame('2026-10-05', $order->tranDate);
        $this->assertSame(SalesOrderOrderStatus::_pendingFulfillment, $order->orderStatus);
        $this->assertSame('Pending Fulfillment', $order->status);
        $this->assertSame(245.75, $order->total);
        $this->assertEquals($this->ref('107', 'Acme Inc.'), $order->entity);
        $this->assertSame(Country::_unitedStates, $order->shippingAddress->country);
        $this->assertSame('custbody_channel', $order->customFieldList->customField[0]->scriptId);

        $this->assertInstanceOf(SalesOrderItemList::class, $order->itemList);
        $this->assertCount(2, $order->itemList->item);
        list($first, $second) = $order->itemList->item;
        $this->assertInstanceOf(SalesOrderItem::class, $first);
        $this->assertEquals($this->ref('11', 'WIDGET'), $first->item);
        $this->assertSame(2.0, $first->quantity);
        $this->assertSame('100', $first->rate);
        $this->assertSame(200.0, $first->amount);
        $this->assertSame(1, $first->line);
        $this->assertCustomField(BooleanCustomFieldRef::class, false, $first->customFieldList->customField[0]);
        $this->assertSame(1.5, $second->quantity);
        $this->assertSame('30.5', $second->rate);
        $this->assertNull($second->price);
        $this->assertNull($second->customFieldList);
    }

    public function testUnknownKeysAndLinksAreIgnored()
    {
        $customer = $this->hydrator->hydrate('customer', [
            'links' => [['rel' => 'self', 'href' => 'https://example.test']],
            'unknownRestOnlyField' => 'x',
            'entityId' => 'Acme',
            'nullField' => null,
            'terms' => ['links' => []],
        ]);

        $this->assertSame(['entityId' => 'Acme'], $this->setProperties($customer));
    }

    public function testNamesMatchCaseInsensitively()
    {
        $customer = $this->hydrator->hydrate('NetSuite\Classes\Customer', [
            'ENTITYID' => 'Acme',
            'addressbooklist' => ['items' => [['Label' => 'HQ']]],
            'CompanyName' => 'Acme Inc.',
        ]);

        $this->assertSame('Acme', $customer->entityId);
        $this->assertSame('Acme Inc.', $customer->companyName);
        $this->assertSame('HQ', $customer->addressbookList->addressbook[0]->label);
    }

    public function testCustomKeyMatchingAStandardFieldStaysStandard()
    {
        $customer = $this->hydrator->hydrate('customer', ['customForm' => ['id' => '-2']]);

        $this->assertEquals($this->ref('-2', null), $customer->customForm);
        $this->assertNull($customer->customFieldList);
    }

    public function testValuesOfTheWrongShapeAreDropped()
    {
        $customer = $this->hydrator->hydrate('customer', [
            'isPerson' => 'maybe',
            'daysOverdue' => 'many',
            'creditLimit' => ['id' => '1'],
            'entityId' => ['nested' => true],
            'subsidiary' => true,
            'addressBook' => 'none',
        ]);

        $this->assertSame([], $this->setProperties($customer));
    }

    public function testScalarCoercions()
    {
        $customer = $this->hydrator->hydrate('customer', [
            'entityId' => 42,
            'isPerson' => 'true',
            'daysOverdue' => '5',
            'creditLimit' => '10.5',
            'subsidiary' => 3,
            'phone' => ['id' => '555', 'refName' => 'Main'],
        ]);

        $this->assertSame('42', $customer->entityId);
        $this->assertTrue($customer->isPerson);
        $this->assertSame(5, $customer->daysOverdue);
        $this->assertSame(10.5, $customer->creditLimit);
        $this->assertEquals($this->ref('3', null), $customer->subsidiary);
        $this->assertSame('555', $customer->phone);
    }

    public function testUnknownEnumValuePassesThrough()
    {
        $customer = $this->hydrator->hydrate('customer', [
            'emailPreference' => 'HTML',
            'addressBook' => ['items' => [['addressBookAddress' => ['country' => ['id' => 'ZZ']]]]],
        ]);

        $this->assertSame('HTML', $customer->emailPreference);
        $this->assertSame('ZZ', $customer->addressbookList->addressbook[0]->addressbookAddress->country);
    }

    public function testFieldNameOverrides()
    {
        $hydrator = new RecordHydrator(new FieldNameMap(['Customer' => ['entityId' => 'name']]));

        $this->assertSame('Acme', $hydrator->hydrate('customer', ['name' => 'Acme'])->entityId);
    }

    public function testUnknownClassThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->hydrator->hydrate('noSuchRecord', []);
    }

    public function testSerializerRoundTripKeepsBodyFields()
    {
        $original = $this->hydrator->hydrate('customer', $this->fixture('customer'));
        $order = $this->hydrator->hydrate('salesOrder', $this->fixture('salesOrder'));
        $serializer = new RecordSerializer();

        foreach ([$original, $order] as $record) {
            $body = $serializer->serialize($record)->body();
            $again = $this->hydrator->hydrate(get_class($record), json_decode(json_encode($body), true));
            $again->internalId = $record->internalId;

            $this->assertEquals($this->withoutNames($record), $this->withoutNames($again));
        }
    }

    public function testRoundTripFromGeneratedRecord()
    {
        $customer = new Customer();
        $customer->entityId = 'Acme';
        $customer->isPerson = true;
        $customer->creditLimit = 1500.0;
        $customer->subsidiary = $this->ref('1', null);
        $customer->emailPreference = EmailPreference::_hTML;
        $customer->addressbookList = new CustomerAddressbookList();
        $line = new CustomerAddressbook();
        $line->label = 'HQ';
        $line->addressbookAddress = new Address();
        $line->addressbookAddress->country = Country::_germany;
        $customer->addressbookList->addressbook = [$line];

        $body = (new RecordSerializer())->serialize($customer)->body();
        $again = $this->hydrator->hydrate('customer', json_decode(json_encode($body), true));

        $this->assertEquals($customer, $again);
    }

    private function assertCustomField(string $class, $value, $field)
    {
        $this->assertInstanceOf($class, $field);
        $this->assertSame($value, $field->value);
    }

    /**
     * Reference names come only from REST, so they are cleared before comparing.
     */
    private function withoutNames($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'withoutNames'], $value);
        }
        if (!is_object($value)) {
            return $value;
        }
        $copy = clone $value;
        foreach (get_object_vars($copy) as $property => $v) {
            $copy->$property = $property === 'name' ? null : $this->withoutNames($v);
        }
        return $copy;
    }

    private function setProperties($object): array
    {
        return array_filter(get_object_vars($object), function ($v) {
            return $v !== null;
        });
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/records/'.$name.'.json'), true);
    }

    private function ref(string $internalId, ?string $name): RecordRef
    {
        $ref = new RecordRef();
        $ref->internalId = $internalId;
        $ref->name = $name;
        return $ref;
    }

    private function listRef(string $internalId, ?string $name): ListOrRecordRef
    {
        $ref = new ListOrRecordRef();
        $ref->internalId = $internalId;
        $ref->name = $name;
        return $ref;
    }
}
