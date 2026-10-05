<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Record;

use NetSuite\Classes\Address;
use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Customer;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SearchStringField;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Record\RecordTypeResolver;
use PHPUnit\Framework\TestCase;

class RecordTypeResolverTest extends TestCase
{
    /** @var RecordTypeResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->resolver = new RecordTypeResolver();
    }

    public function testRecordObjects()
    {
        $this->assertSame('customer', $this->resolver->resolve(new Customer()));
        $this->assertSame('salesOrder', $this->resolver->resolve(new SalesOrder()));
    }

    public function testRecordClassNames()
    {
        $this->assertSame('salesOrder', $this->resolver->resolve(SalesOrder::class));
        $this->assertSame('customer', $this->resolver->resolve('\\'.Customer::class));
    }

    public function testRecordTypeValues()
    {
        $this->assertSame('salesOrder', $this->resolver->resolve(RecordType::salesOrder));
        $this->assertSame('inventoryItem', $this->resolver->resolve('inventoryitem'));
        $this->assertSame('customRecordType', $this->resolver->resolve(RecordType::customRecordType));
    }

    public function testRecordRefUsesType()
    {
        $ref = new RecordRef();
        $ref->internalId = '42';
        $ref->type = RecordType::invoice;

        $this->assertSame('invoice', $this->resolver->resolve($ref));
    }

    public function testCustomRecordScriptIds()
    {
        $this->assertSame('customrecord_widget', $this->resolver->resolve('customrecord_widget'));
        $this->assertSame('customrecord_widget', $this->resolver->resolve('CUSTOMRECORD_Widget'));
        $this->assertSame('customrecord123', $this->resolver->resolve('customrecord123'));
    }

    public function testCustomRecordRefByScriptId()
    {
        $ref = new CustomRecordRef();
        $ref->internalId = '5';
        $ref->typeId = '17';
        $ref->scriptId = 'customrecord_widget';

        $this->assertSame('customrecord_widget', $this->resolver->resolve($ref));
    }

    public function testCustomRecordRefWithOnlyTypeIdIsNotSupported()
    {
        $ref = new CustomRecordRef();
        $ref->internalId = '5';
        $ref->typeId = '17';

        $this->expectException(NotSupportedOnRestException::class);
        $this->expectExceptionMessage('"17"');

        $this->resolver->resolve($ref);
    }

    public function testCustomRecordRefWithoutTypeIsInvalid()
    {
        $ref = new CustomRecordRef();
        $ref->internalId = '5';

        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolve($ref);
    }

    public function testCustomRecordWithNumericRecTypeIsNotSupported()
    {
        $record = new CustomRecord();
        $record->recType = new RecordRef();
        $record->recType->internalId = '17';

        $this->expectException(NotSupportedOnRestException::class);

        $this->resolver->resolve($record);
    }

    public function testGenericCustomRecordTypeIsNotSupported()
    {
        $this->expectException(NotSupportedOnRestException::class);

        $this->resolver->resolve(RecordType::customRecord);
    }

    /**
     * @dataProvider invalidTargets
     */
    public function testInvalidTargets($target)
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->resolver->resolve($target);
    }

    public function invalidTargets(): array
    {
        return [
            'unknown value'      => ['spaceship'],
            'empty string'       => [''],
            'bare customrecord'  => ['customrecord_'],
            'subrecord object'   => [new Address()],
            'non-record class'   => [SearchStringField::class],
            'non-record object'  => [new SearchStringField()],
            'record ref no type' => [new RecordRef()],
            'integer'            => [42],
            'null'               => [null],
        ];
    }
}
