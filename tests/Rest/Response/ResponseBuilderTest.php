<?php

namespace tests\Netsuite\Rest\Response;

use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Customer;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\ReadResponseList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\WriteResponse;
use NetSuite\Classes\WriteResponseList;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;
use NetSuite\Rest\Response\ResponseBuilder;
use PHPUnit\Framework\TestCase;

class ResponseBuilderTest extends TestCase
{
    /** @var ResponseBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->builder = new ResponseBuilder();
    }

    public function testRecordRef()
    {
        $ref = $this->builder->recordRef('42', 'CUST_42', RecordType::customer);

        $this->assertInstanceOf(RecordRef::class, $ref);
        $this->assertSame('42', $ref->internalId);
        $this->assertSame('CUST_42', $ref->externalId);
        $this->assertSame(RecordType::customer, $ref->type);
    }

    public function testWriteSuccess()
    {
        $ref = $this->builder->recordRef('42', null, RecordType::customer);
        $response = $this->builder->writeSuccess($ref);

        $this->assertInstanceOf(WriteResponse::class, $response);
        $this->assertTrue($response->status->isSuccess);
        $this->assertSame($ref, $response->baseRef);
    }

    public function testWriteSuccessWithCustomRecordRef()
    {
        $ref = new CustomRecordRef();
        $ref->internalId = '7';
        $ref->scriptId = 'customrecord_box';

        $this->assertSame($ref, $this->builder->writeSuccess($ref)->baseRef);
    }

    public function testWriteFailure()
    {
        $response = $this->builder->writeFailure($this->notFound());

        $this->assertFalse($response->status->isSuccess);
        $this->assertSame(StatusDetailCodeType::NONEXISTENT_ID, $response->status->statusDetail[0]->code);
        $this->assertNull($response->baseRef);

        $ref = $this->builder->recordRef('42');
        $this->assertSame($ref, $this->builder->writeFailure($this->notFound(), $ref)->baseRef);
    }

    public function testWriteList()
    {
        $ok = $this->builder->writeSuccess($this->builder->recordRef('1'));
        $failed = $this->builder->writeFailure($this->notFound());

        $list = $this->builder->writeList([3 => $ok, 5 => $failed]);

        $this->assertInstanceOf(WriteResponseList::class, $list);
        $this->assertTrue($list->status->isSuccess);
        $this->assertSame([$ok, $failed], $list->writeResponse);
    }

    public function testEmptyWriteList()
    {
        $list = $this->builder->writeList([]);

        $this->assertTrue($list->status->isSuccess);
        $this->assertSame([], $list->writeResponse);
    }

    public function testReadSuccess()
    {
        $customer = new Customer();
        $response = $this->builder->readSuccess($customer);

        $this->assertInstanceOf(ReadResponse::class, $response);
        $this->assertTrue($response->status->isSuccess);
        $this->assertSame($customer, $response->record);
    }

    public function testReadFailure()
    {
        $response = $this->builder->readFailure($this->notFound());

        $this->assertFalse($response->status->isSuccess);
        $this->assertSame('That record does not exist.', $response->status->statusDetail[0]->message);
        $this->assertNull($response->record);
    }

    public function testReadList()
    {
        $ok = $this->builder->readSuccess(new Customer());
        $failed = $this->builder->readFailure($this->notFound());

        $list = $this->builder->readList([$ok, $failed]);

        $this->assertInstanceOf(ReadResponseList::class, $list);
        $this->assertTrue($list->status->isSuccess);
        $this->assertSame([$ok, $failed], $list->readResponse);
    }

    private function notFound(): RestError
    {
        return new RestError(404, 'Record Not Found', [
            new RestErrorDetail('That record does not exist.', 'NONEXISTENT_ID'),
        ]);
    }
}
