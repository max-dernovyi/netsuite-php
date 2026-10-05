<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Mapping;

use NetSuite\Classes\Account;
use NetSuite\Classes\Address;
use NetSuite\Classes\BooleanCustomFieldRef;
use NetSuite\Classes\Country;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerAddressbook;
use NetSuite\Classes\CustomerAddressbookList;
use NetSuite\Classes\DateCustomFieldRef;
use NetSuite\Classes\DoubleCustomFieldRef;
use NetSuite\Classes\EmailPreference;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\LongCustomFieldRef;
use NetSuite\Classes\MultiSelectCustomFieldRef;
use NetSuite\Classes\NullField;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordRefList;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\SalesOrderOrderStatus;
use NetSuite\Classes\SelectCustomFieldRef;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Mapping\FieldNameMap;
use NetSuite\Rest\Mapping\RecordSerializer;
use PHPUnit\Framework\TestCase;

class RecordSerializerTest extends TestCase
{
    /** @var RecordSerializer */
    private $serializer;

    protected function setUp(): void
    {
        $this->serializer = new RecordSerializer();
    }

    public function testCustomerBodyFields()
    {
        $customer = new Customer();
        $customer->internalId = '42';
        $customer->externalId = 'CUST_42';
        $customer->entityId = 'Acme';
        $customer->isPerson = false;
        $customer->taxExempt = 'true';
        $customer->creditLimit = '1500';
        $customer->daysOverdue = '3';
        $customer->dateCreated = '2026-01-02T10:15:30.000-07:00';
        $customer->subsidiary = $this->ref('1');
        $customer->parent = $this->ref(null, 'PARENT_1');
        $customer->emailPreference = EmailPreference::_pDF;

        $result = $this->serializer->serialize($customer);

        $expected = [
            'entityId' => 'Acme',
            'isPerson' => false,
            'parent' => ['externalId' => 'PARENT_1'],
            'dateCreated' => '2026-01-02T10:15:30-07:00',
            'emailPreference' => ['id' => '_pDF'],
            'subsidiary' => ['id' => '1'],
            'taxExempt' => true,
            'creditLimit' => 1500.0,
            'daysOverdue' => 3,
            'externalId' => 'CUST_42',
        ];
        $body = $result->body();
        ksort($expected);
        ksort($body);
        $this->assertSame($expected, $body);
        $this->assertSame([], $result->replace());
    }

    public function testEveryCustomFieldType()
    {
        $customer = new Customer();
        $customer->customFieldList = new CustomFieldList();
        $customer->customFieldList->customField = [
            $this->customField(new StringCustomFieldRef(), 'custentity_text', 'hello'),
            $this->customField(new BooleanCustomFieldRef(), 'custentity_flag', true),
            $this->customField(new LongCustomFieldRef(), 'custentity_count', '7'),
            $this->customField(new DoubleCustomFieldRef(), 'custentity_ratio', '0.5'),
            $this->customField(new DateCustomFieldRef(), 'custentity_since', '2026-03-04T00:00:00Z'),
            $this->customField(new SelectCustomFieldRef(), 'custentity_tier', $this->listRef('3')),
            $this->customField(new SelectCustomFieldRef(), 'custentity_owner', $this->listRef(null, 'EMP_9')),
            $this->customField(new MultiSelectCustomFieldRef(), 'custentity_tags', [$this->listRef('1'), $this->listRef('2')]),
            $this->customField(new StringCustomFieldRef(), 'custentity_empty', null),
        ];
        $legacy = new StringCustomFieldRef();
        $legacy->internalId = 'custentity_legacy';
        $legacy->value = 'by internalId';
        $customer->customFieldList->customField[] = $legacy;

        $this->assertSame([
            'custentity_text' => 'hello',
            'custentity_flag' => true,
            'custentity_count' => 7,
            'custentity_ratio' => 0.5,
            'custentity_since' => '2026-03-04T00:00:00+00:00',
            'custentity_tier' => ['id' => '3'],
            'custentity_owner' => ['externalId' => 'EMP_9'],
            'custentity_tags' => ['items' => [['id' => '1'], ['id' => '2']]],
            'custentity_legacy' => 'by internalId',
        ], $this->serializer->serialize($customer)->body());
    }

    public function testSingleCustomFieldWithoutArray()
    {
        $customer = new Customer();
        $customer->customFieldList = new CustomFieldList();
        $customer->customFieldList->customField = $this->customField(new StringCustomFieldRef(), 'custentity_a', 'x');

        $this->assertSame(['custentity_a' => 'x'], $this->serializer->serialize($customer)->body());
    }

    public function testCustomFieldByNumericIdIsNotSupported()
    {
        $field = new StringCustomFieldRef();
        $field->internalId = '123';
        $field->value = 'x';
        $customer = new Customer();
        $customer->customFieldList = new CustomFieldList();
        $customer->customFieldList->customField = [$field];

        $this->expectException(NotSupportedOnRestException::class);
        $this->expectExceptionMessage('"123"');
        $this->serializer->serialize($customer);
    }

    public function testSalesOrderItemListWithReplaceAll()
    {
        $order = new SalesOrder();
        $order->entity = $this->ref('7');
        $order->tranDate = '2026-10-05';
        $order->orderStatus = SalesOrderOrderStatus::_pendingFulfillment;
        $order->itemList = new SalesOrderItemList();
        $order->itemList->replaceAll = true;
        $order->itemList->item = [$this->orderLine('11', 2, null), $this->orderLine('12', '1.5', 3)];

        $result = $this->serializer->serialize($order);

        $this->assertSame(['id' => '7'], $result->body()['entity']);
        $this->assertSame('2026-10-05', $result->body()['tranDate']);
        $this->assertSame(['id' => 'B'], $result->body()['orderStatus']);
        $this->assertSame(['items' => [
            ['item' => ['id' => '11'], 'quantity' => 2.0],
            ['item' => ['id' => '12'], 'quantity' => 1.5, 'line' => 3],
        ]], $result->body()['item']);
        $this->assertArrayNotHasKey('itemList', $result->body());
        $this->assertSame(['item'], $result->replace());
    }

    public function testReplaceAllDefaultsToTrue()
    {
        $order = new SalesOrder();
        $order->itemList = new SalesOrderItemList();
        $order->itemList->item = $this->orderLine('11', 1, null);

        $result = $this->serializer->serialize($order);

        $this->assertSame(['item'], $result->replace());
        $this->assertCount(1, $result->body()['item']['items']);
    }

    public function testReplaceAllFalseMerges()
    {
        $order = new SalesOrder();
        $order->itemList = new SalesOrderItemList();
        $order->itemList->replaceAll = false;
        $order->itemList->item = [$this->orderLine('11', 1, 1)];

        $this->assertSame([], $this->serializer->serialize($order)->replace());
    }

    public function testEmptySublistWithReplaceAllClearsIt()
    {
        $order = new SalesOrder();
        $order->itemList = new SalesOrderItemList();
        $order->itemList->replaceAll = true;

        $result = $this->serializer->serialize($order);

        $this->assertSame(['item' => ['items' => []]], $result->body());
        $this->assertSame(['item'], $result->replace());
    }

    public function testAddressBookSubrecord()
    {
        $address = new Address();
        $address->country = Country::_unitedStates;
        $address->addr1 = '1 Main St';
        $address->city = 'Springfield';
        $address->override = false;
        $line = new CustomerAddressbook();
        $line->internalId = '5';
        $line->defaultShipping = true;
        $line->label = 'HQ';
        $line->addressbookAddress = $address;
        $customer = new Customer();
        $customer->addressbookList = new CustomerAddressbookList();
        $customer->addressbookList->addressbook = [$line];

        $result = $this->serializer->serialize($customer);

        $this->assertSame(['addressBook' => ['items' => [[
            'defaultShipping' => true,
            'label' => 'HQ',
            'addressBookAddress' => [
                'country' => ['id' => 'US'],
                'addr1' => '1 Main St',
                'city' => 'Springfield',
                'override' => false,
            ],
            'internalId' => '5',
        ]]]], $result->body());
        $this->assertSame(['addressBook'], $result->replace());
    }

    public function testNullFieldList()
    {
        $customer = new Customer();
        $customer->phone = '555';
        $customer->comments = 'kept';
        $customer->nullFieldList = new NullField();
        $customer->nullFieldList->name = ['phone', 'custentity_old', 'addressbookList', 'salesTeamList'];

        $this->assertSame([
            'phone' => null,
            'comments' => 'kept',
            'custentity_old' => null,
            'addressBook' => null,
            'salesTeam' => null,
        ], $this->serializer->serialize($customer)->body());
    }

    public function testNullFieldListOnSubrecord()
    {
        $address = new Address();
        $address->city = 'Springfield';
        $address->nullFieldList = new NullField();
        $address->nullFieldList->name = 'addr2';
        $order = new SalesOrder();
        $order->shippingAddress = $address;

        $this->assertSame(
            ['shippingAddress' => ['city' => 'Springfield', 'addr2' => null]],
            $this->serializer->serialize($order)->body()
        );
    }

    public function testUnknownEnumValuePassesThrough()
    {
        $address = new Address();
        $address->country = '_atlantis';
        $customer = new Customer();
        $customer->language = '_english';
        $customer->defaultAddress = 'x';
        $order = new SalesOrder();
        $order->shippingAddress = $address;

        $this->assertSame(['id' => '_atlantis'], $this->serializer->serialize($order)->body()['shippingAddress']['country']);
        $this->assertSame(['id' => '_english'], $this->serializer->serialize($customer)->body()['language']);
    }

    public function testNullPropertiesAreOmitted()
    {
        $customer = new Customer();
        $customer->entityId = 'Acme';
        $customer->subsidiary = new RecordRef();
        $customer->addressbookList = null;
        $order = new SalesOrder();
        $order->shippingAddress = new Address();
        $order->memo = 'm';

        $this->assertSame(['entityId' => 'Acme'], $this->serializer->serialize($customer)->body());
        $this->assertSame(['memo' => 'm'], $this->serializer->serialize($order)->body());
    }

    public function testMultiSelectBodyFieldKeepsItsName()
    {
        $account = new Account();
        $account->subsidiaryList = new RecordRefList();
        $account->subsidiaryList->recordRef = [$this->ref('1'), $this->ref('2')];

        $result = $this->serializer->serialize($account);

        $this->assertSame(['subsidiaryList' => ['items' => [['id' => '1'], ['id' => '2']]]], $result->body());
        $this->assertSame([], $result->replace());
    }

    public function testDateTimeObjects()
    {
        $customer = new Customer();
        $customer->dateCreated = new \DateTimeImmutable('2026-05-06 07:08:09', new \DateTimeZone('UTC'));

        $this->assertSame('2026-05-06T07:08:09+00:00', $this->serializer->serialize($customer)->body()['dateCreated']);
    }

    public function testFieldNameOverrides()
    {
        $serializer = new RecordSerializer(new FieldNameMap(['Customer' => ['entityId' => 'entityid']]));
        $customer = new Customer();
        $customer->entityId = 'Acme';

        $this->assertSame(['entityid' => 'Acme'], $serializer->serialize($customer)->body());
    }

    public function testJsonShape()
    {
        $order = new SalesOrder();
        $order->entity = $this->ref('7');
        $order->itemList = new SalesOrderItemList();
        $order->itemList->item = [$this->orderLine('11', 1, null)];

        $this->assertSame(
            '{"entity":{"id":"7"},"item":{"items":[{"item":{"id":"11"},"quantity":1.0}]}}',
            json_encode($this->serializer->serialize($order)->body(), JSON_PRESERVE_ZERO_FRACTION)
        );
    }

    /**
     * @dataProvider mismatches
     */
    public function testTypeMismatchesThrow(string $field, $value, string $message)
    {
        $customer = new Customer();
        $customer->$field = $value;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->serializer->serialize($customer);
    }

    public function mismatches(): array
    {
        return [
            'string' => ['entityId', ['a'], 'Customer.entityId expects string, got array'],
            'boolean' => ['isPerson', 'maybe', 'Customer.isPerson expects boolean'],
            'integer' => ['daysOverdue', '1.5', 'Customer.daysOverdue expects integer'],
            'float' => ['creditLimit', 'lots', 'Customer.creditLimit expects float'],
            'dateTime' => ['dateCreated', 'yesterday', 'Customer.dateCreated expects dateTime'],
            'bad date' => ['dateCreated', '2026-13-45Tnope', 'Customer.dateCreated expects dateTime'],
            'object' => ['subsidiary', 5, 'Customer.subsidiary expects RecordRef, got integer'],
            'sublist' => ['addressbookList', 'x', 'Customer.addressbookList expects CustomerAddressbookList'],
        ];
    }

    public function testNonObjectRecordThrows()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->serializer->serialize('customer');
    }

    private function ref(?string $internalId, ?string $externalId = null): RecordRef
    {
        $ref = new RecordRef();
        $ref->internalId = $internalId;
        $ref->externalId = $externalId;
        return $ref;
    }

    private function listRef(?string $internalId, ?string $externalId = null): ListOrRecordRef
    {
        $ref = new ListOrRecordRef();
        $ref->internalId = $internalId;
        $ref->externalId = $externalId;
        return $ref;
    }

    private function customField($field, string $scriptId, $value)
    {
        $field->scriptId = $scriptId;
        $field->value = $value;
        return $field;
    }

    private function orderLine(string $item, $quantity, $line): SalesOrderItem
    {
        $orderLine = new SalesOrderItem();
        $orderLine->item = $this->ref($item);
        $orderLine->quantity = $quantity;
        $orderLine->line = $line;
        return $orderLine;
    }
}
