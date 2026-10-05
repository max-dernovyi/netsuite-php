<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Handler;

use NetSuite\Classes\AddListRequest;
use NetSuite\Classes\AddListResponse;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\AddResponse;
use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Customer;
use NetSuite\Classes\DeleteListRequest;
use NetSuite\Classes\DeleteListResponse;
use NetSuite\Classes\DeleteRequest;
use NetSuite\Classes\DeleteResponse;
use NetSuite\Classes\DeletionReason;
use NetSuite\Classes\NullField;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\UpdateListRequest;
use NetSuite\Classes\UpdateListResponse;
use NetSuite\Classes\UpdateRequest;
use NetSuite\Classes\UpdateResponse;
use NetSuite\Classes\UpsertListRequest;
use NetSuite\Classes\UpsertListResponse;
use NetSuite\Classes\UpsertRequest;
use NetSuite\Classes\UpsertResponse;
use NetSuite\Classes\WriteResponse;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Handler\AddHandler;
use NetSuite\Rest\Handler\DeleteHandler;
use NetSuite\Rest\Handler\GetHandler;
use NetSuite\Rest\Handler\GetListHandler;
use NetSuite\Rest\Handler\HandlerFactory;
use NetSuite\Rest\Handler\UpdateHandler;
use NetSuite\Rest\Handler\UpsertHandler;
use NetSuite\Rest\Handler\WriteListHandler;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Record\RecordClient;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Http\CountingAuthenticator;
use tests\Netsuite\Rest\Http\FakeTransport;
use tests\Netsuite\Rest\Http\RecordingSleeper;
use tests\Netsuite\Rest\Http\SpyLogger;

class WriteHandlerTest extends TestCase
{
    const RECORD_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/record/v1';

    /** @var FakeTransport */
    private $transport;
    /** @var RestClient */
    private $client;
    /** @var RecordClient */
    private $records;
    /** @var SpyLogger */
    private $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new RestClient(
            RestConfig::fromArray([
                'transport'      => 'rest',
                'account'        => '123456_SB1',
                'consumerKey'    => 'ck',
                'consumerSecret' => 'cs',
                'token'          => 't',
                'tokenSecret'    => 'ts',
                'maxAttempts'    => 1,
            ]),
            $this->transport,
            new CountingAuthenticator(),
            null,
            new RecordingSleeper()
        );
        $this->records = new RecordClient($this->client);
        $this->logger = new SpyLogger();
    }

    private function created(string $type, string $id): Response
    {
        return new Response(204, ['Location' => self::RECORD_URL.'/'.$type.'/'.$id], '');
    }

    private function error(int $status, string $name): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/vnd.oracle.resource+json; type=error'],
            (string) file_get_contents(__DIR__.'/../Fixtures/errors/'.$name.'.json')
        );
    }

    private function customer(?string $internalId = null, ?string $externalId = null, string $name = 'Acme'): Customer
    {
        $customer = new Customer();
        $customer->internalId = $internalId;
        $customer->externalId = $externalId;
        $customer->companyName = $name;
        return $customer;
    }

    private function ref(string $type, ?string $internalId, ?string $externalId = null): RecordRef
    {
        $ref = new RecordRef();
        $ref->type = $type;
        $ref->internalId = $internalId;
        $ref->externalId = $externalId;
        return $ref;
    }

    private function request(int $i = 0): Request
    {
        return $this->transport->requests[$i];
    }

    private function body(int $i = 0): ?array
    {
        $body = $this->request($i)->getBody();
        return $body === null || $body === '' ? null : json_decode($body, true);
    }

    private function assertSuccess(WriteResponse $write, ?string $internalId, ?string $externalId, string $type): void
    {
        $this->assertTrue($write->status->isSuccess);
        $this->assertInstanceOf(RecordRef::class, $write->baseRef);
        $this->assertSame($internalId, $write->baseRef->internalId);
        $this->assertSame($externalId, $write->baseRef->externalId);
        $this->assertSame($type, $write->baseRef->type);
    }

    private function assertFailure(WriteResponse $write, string $code, ?string $message = null): void
    {
        $this->assertFalse($write->status->isSuccess);
        $this->assertNull($write->baseRef);
        $this->assertSame($code, $write->status->statusDetail[0]->code);
        $this->assertSame('ERROR', $write->status->statusDetail[0]->type);
        if ($message !== null) {
            $this->assertStringContainsString($message, $write->status->statusDetail[0]->message);
        }
    }

    public function testAddPostsTheRecordAndReadsTheIdFromLocation()
    {
        $this->transport->push($this->created('customer', '647'));
        $request = new AddRequest();
        $request->record = $this->customer('1', 'CUST_647');

        $response = (new AddHandler($this->records))->handle($request);

        $this->assertInstanceOf(AddResponse::class, $response);
        $this->assertSuccess($response->writeResponse, '647', 'CUST_647', RecordType::customer);
        $this->assertSame('POST', $this->request()->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer', $this->request()->getUrl());
        $this->assertSame(['companyName' => 'Acme', 'externalId' => 'CUST_647'], $this->body());
    }

    public function testAddCustomRecordReturnsACustomRecordRef()
    {
        $this->transport->push($this->created('customrecord_widget', '9'));
        $record = new CustomRecord();
        $record->name = 'Blue widget';
        $record->recType = new RecordRef();
        $record->recType->internalId = 'customrecord_widget';

        $write = (new AddHandler($this->records))->write($record);

        $this->assertSame(self::RECORD_URL.'/customrecord_widget', $this->request()->getUrl());
        $this->assertSame(['name' => 'Blue widget'], $this->body());
        $this->assertTrue($write->status->isSuccess);
        $this->assertInstanceOf(CustomRecordRef::class, $write->baseRef);
        $this->assertSame('9', $write->baseRef->internalId);
        $this->assertSame('customrecord_widget', $write->baseRef->scriptId);
        $this->assertSame('customrecord_widget', $write->baseRef->typeId);
    }

    public function testAddErrorBecomesAStatus()
    {
        $this->transport->push($this->error(400, 'invalid-content'));

        $write = (new AddHandler($this->records))->write($this->customer());

        $this->assertFailure($write, StatusDetailCodeType::INVALID_CONTENT, 'Invalid Field Value 999');
        $this->assertCount(2, $write->status->statusDetail);
        $this->assertSame(StatusDetailCodeType::USER_ERROR, $write->status->statusDetail[1]->code);
    }

    /**
     * @dataProvider invalidRecordProvider
     */
    public function testInvalidRecordsFailWithoutARequest($record, string $code)
    {
        $this->assertFailure((new AddHandler($this->records))->write($record), $code);
        $this->assertSame([], $this->transport->requests);
    }

    public static function invalidRecordProvider(): array
    {
        $badValue = new Customer();
        $badValue->isPerson = 'maybe';
        $badUtf8 = new Customer();
        $badUtf8->companyName = "Caf\xE9";
        $notFinite = new Customer();
        $notFinite->creditLimit = NAN;

        return [
            'null'        => [null, StatusDetailCodeType::INVALID_RCRD_TYPE],
            'not a record' => [new RecordRef(), StatusDetailCodeType::INVALID_RCRD_TYPE],
            'bad value'   => [$badValue, StatusDetailCodeType::INVALID_FLD_VALUE],
            'invalid utf-8' => [$badUtf8, StatusDetailCodeType::INVALID_FLD_VALUE],
            'not finite'  => [$notFinite, StatusDetailCodeType::INVALID_FLD_VALUE],
        ];
    }

    public function testAddCustomRecordKnownOnlyByTypeIdIsNotSupported()
    {
        $record = new CustomRecord();
        $record->recType = new RecordRef();
        $record->recType->internalId = '314';

        $this->expectException(NotSupportedOnRestException::class);
        (new AddHandler($this->records))->write($record);
    }

    public function testAddFaultIsThrownAndNotRetried()
    {
        $this->transport->push(new Response(500, [], '{"title":"Internal Server Error","status":500}'));

        try {
            (new AddHandler($this->records))->write($this->customer());
            $this->fail('RestFault expected');
        } catch (RestFault $fault) {
            $this->assertCount(1, $this->transport->requests);
        }
    }

    public function testUpdateByInternalIdWithReplaceAllAndNulls()
    {
        $this->transport->push(new Response(204, [], ''));
        $order = new SalesOrder();
        $order->internalId = '5012';
        $order->memo = 'rush';
        $order->itemList = new SalesOrderItemList();
        $order->itemList->replaceAll = true;
        $line = new SalesOrderItem();
        $line->item = $this->ref(RecordType::inventoryItem, '31');
        $line->quantity = 2;
        $order->itemList->item = [$line];
        $order->nullFieldList = new NullField();
        $order->nullFieldList->name = ['otherRefNum'];
        $request = new UpdateRequest();
        $request->record = $order;

        $response = (new UpdateHandler($this->records))->handle($request);

        $this->assertInstanceOf(UpdateResponse::class, $response);
        $this->assertSuccess($response->writeResponse, '5012', null, RecordType::salesOrder);
        $this->assertSame('PATCH', $this->request()->getMethod());
        $this->assertSame(self::RECORD_URL.'/salesOrder/5012?replace=item', $this->request()->getUrl());
        $this->assertSame([
            'memo' => 'rush',
            'item' => ['items' => [['item' => ['id' => '31'], 'quantity' => 2.0]]],
            'otherRefNum' => null,
        ], $this->body());
    }

    public function testUpdateWithoutReplacedSublistsHasNoReplaceQuery()
    {
        $this->transport->push(new Response(204, [], ''));
        $customer = $this->customer('107');
        $customer->nullFieldList = new NullField();
        $customer->nullFieldList->name = 'phone';

        (new UpdateHandler($this->records))->write($customer);

        $this->assertSame(self::RECORD_URL.'/customer/107', $this->request()->getUrl());
        $this->assertSame(['companyName' => 'Acme', 'phone' => null], $this->body());
    }

    public function testUpdateByExternalIdTakesTheIdFromLocation()
    {
        $this->transport->push($this->created('customer', '107'));

        $write = (new UpdateHandler($this->records))->write($this->customer(null, 'CUST_107'));

        $this->assertSame(self::RECORD_URL.'/customer/eid:CUST_107', $this->request()->getUrl());
        $this->assertSuccess($write, '107', 'CUST_107', RecordType::customer);
    }

    public function testUpdateByExternalIdWithoutLocationHasNoInternalId()
    {
        $this->transport->push(new Response(204, [], ''));

        $write = (new UpdateHandler($this->records))->write($this->customer(null, 'CUST_107'));

        $this->assertSuccess($write, null, 'CUST_107', RecordType::customer);
    }

    public function testUpdateWithoutIdsFailsWithoutARequest()
    {
        $write = (new UpdateHandler($this->records))->write($this->customer());

        $this->assertFailure($write, StatusDetailCodeType::INVALID_KEY_OR_REF);
        $this->assertSame([], $this->transport->requests);
    }

    public function testUpdateNotFoundBecomesAStatus()
    {
        $this->transport->push($this->error(404, 'not-found'));

        $write = (new UpdateHandler($this->records))->write($this->customer('999'));

        $this->assertFailure($write, StatusDetailCodeType::NONEXISTENT_ID);
    }

    public function testUpsertPutsByExternalId()
    {
        $this->transport->push($this->created('customer', '648'));
        $request = new UpsertRequest();
        $request->record = $this->customer(null, 'CUST_648');

        $response = (new UpsertHandler($this->records))->handle($request);

        $this->assertInstanceOf(UpsertResponse::class, $response);
        $this->assertSuccess($response->writeResponse, '648', 'CUST_648', RecordType::customer);
        $this->assertSame('PUT', $this->request()->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer/eid:CUST_648', $this->request()->getUrl());
        $this->assertSame(['companyName' => 'Acme', 'externalId' => 'CUST_648'], $this->body());
    }

    public function testUpsertWithoutExternalIdFailsWithoutARequest()
    {
        $write = (new UpsertHandler($this->records))->write($this->customer('648'));

        $this->assertFailure($write, StatusDetailCodeType::INVALID_KEY_OR_REF, 'externalId');
        $this->assertSame([], $this->transport->requests);
    }

    public function testUpsertWithAnInvalidExternalIdFailsWithoutARequest()
    {
        $write = (new UpsertHandler($this->records))->write($this->customer(null, 'bad id'));

        $this->assertFailure($write, StatusDetailCodeType::INVALID_KEY_OR_REF, 'Invalid external id');
        $this->assertSame([], $this->transport->requests);
    }

    public function testUpsertErrorBecomesAStatus()
    {
        $this->transport->push($this->error(400, 'invalid-content'));

        $write = (new UpsertHandler($this->records))->write($this->customer(null, 'CUST_648'));

        $this->assertFailure($write, StatusDetailCodeType::INVALID_CONTENT);
    }

    public function testDeleteByInternalId()
    {
        $this->transport->push(new Response(204, [], ''));
        $request = new DeleteRequest();
        $request->baseRef = $this->ref('CUSTOMER', '107');

        $response = (new DeleteHandler($this->records, $this->logger))->handle($request);

        $this->assertInstanceOf(DeleteResponse::class, $response);
        $this->assertSuccess($response->writeResponse, '107', null, RecordType::customer);
        $this->assertSame('DELETE', $this->request()->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer/107', $this->request()->getUrl());
        $this->assertNull($this->body());
        $this->assertSame([], $this->logger->records);
    }

    public function testDeleteByExternalIdLogsTheIgnoredDeletionReason()
    {
        $this->transport->push(new Response(204, [], ''));
        $request = new DeleteRequest();
        $request->baseRef = $this->ref(RecordType::customer, null, 'CUST_107');
        $request->deletionReason = new DeletionReason();
        $request->deletionReason->deletionReasonMemo = 'duplicate';

        $write = (new DeleteHandler($this->records, $this->logger))->handle($request)->writeResponse;

        $this->assertSame(self::RECORD_URL.'/customer/eid:CUST_107', $this->request()->getUrl());
        $this->assertSuccess($write, null, 'CUST_107', RecordType::customer);
        $this->assertSame([[
            'level' => 'debug',
            'message' => 'NetSuite REST: operation "delete" ignores deletionReason',
            'context' => ['operation' => 'delete'],
        ]], $this->logger->records);
    }

    public function testDeleteCustomRecord()
    {
        $this->transport->push(new Response(204, [], ''));
        $ref = new CustomRecordRef();
        $ref->internalId = '9';
        $ref->scriptId = 'customrecord_widget';
        $ref->typeId = '314';

        $write = (new DeleteHandler($this->records))->write($ref);

        $this->assertSame(self::RECORD_URL.'/customrecord_widget/9', $this->request()->getUrl());
        $this->assertInstanceOf(CustomRecordRef::class, $write->baseRef);
        $this->assertSame('9', $write->baseRef->internalId);
        $this->assertSame('314', $write->baseRef->typeId);
        $this->assertSame('customrecord_widget', $write->baseRef->scriptId);
    }

    public function testDeleteNotFoundBecomesAStatus()
    {
        $this->transport->push($this->error(404, 'not-found'));

        $write = (new DeleteHandler($this->records))->write($this->ref(RecordType::customer, '999'));

        $this->assertFailure($write, StatusDetailCodeType::NONEXISTENT_ID);
    }

    /**
     * @dataProvider invalidRefProvider
     */
    public function testDeleteInvalidReferencesFailWithoutARequest($ref, string $code)
    {
        $this->assertFailure((new DeleteHandler($this->records))->write($ref), $code);
        $this->assertSame([], $this->transport->requests);
    }

    public static function invalidRefProvider(): array
    {
        $noType = new RecordRef();
        $noType->internalId = '1';
        $noKey = new RecordRef();
        $noKey->type = RecordType::customer;

        return [
            'not a ref' => [new Customer(), StatusDetailCodeType::INVALID_KEY_OR_REF],
            'no type'   => [$noType, StatusDetailCodeType::INVALID_RCRD_TYPE],
            'no key'    => [$noKey, StatusDetailCodeType::INVALID_KEY_OR_REF],
        ];
    }

    public function testAddListKeepsOrderAndIsolatesFailures()
    {
        $this->transport->push($this->created('customer', '1'));
        $this->transport->push($this->error(400, 'invalid-content'));
        $this->transport->push($this->created('salesOrder', '3'));
        $request = new AddListRequest();
        $request->record = [$this->customer(null, 'A'), $this->customer(null, 'B'), null, new SalesOrder()];

        $response = (new WriteListHandler(new AddHandler($this->records), AddListResponse::class, 'record'))
            ->handle($request);

        $this->assertInstanceOf(AddListResponse::class, $response);
        $list = $response->writeResponseList;
        $this->assertTrue($list->status->isSuccess);
        $this->assertCount(4, $list->writeResponse);
        $this->assertSuccess($list->writeResponse[0], '1', 'A', RecordType::customer);
        $this->assertFailure($list->writeResponse[1], StatusDetailCodeType::INVALID_CONTENT);
        $this->assertFailure($list->writeResponse[2], StatusDetailCodeType::INVALID_RCRD_TYPE);
        $this->assertSuccess($list->writeResponse[3], '3', null, RecordType::salesOrder);
        $this->assertSame(
            [self::RECORD_URL.'/customer', self::RECORD_URL.'/customer', self::RECORD_URL.'/salesOrder'],
            array_map(function (Request $request) {
                return $request->getUrl();
            }, $this->transport->requests)
        );
    }

    public function testUpdateListKeepsOrderAndIsolatesFailures()
    {
        $this->transport->push($this->error(404, 'not-found'));
        $this->transport->push(new Response(204, [], ''));
        $request = new UpdateListRequest();
        $request->record = [$this->customer('999'), $this->customer(), $this->customer('2')];

        $list = (new WriteListHandler(new UpdateHandler($this->records), UpdateListResponse::class, 'record'))
            ->handle($request)->writeResponseList;

        $this->assertFailure($list->writeResponse[0], StatusDetailCodeType::NONEXISTENT_ID);
        $this->assertFailure($list->writeResponse[1], StatusDetailCodeType::INVALID_KEY_OR_REF);
        $this->assertSuccess($list->writeResponse[2], '2', null, RecordType::customer);
        $this->assertCount(2, $this->transport->requests);
    }

    public function testAddListTurnsFaultsAfterTheFirstItemIntoStatuses()
    {
        $this->transport->push($this->created('customer', '1'));
        $this->transport->push(new Response(500, [], '{"title":"Internal Server Error","status":500}'));
        $this->transport->push($this->created('customer', '3'));
        $typeIdOnly = new CustomRecord();
        $typeIdOnly->recType = new RecordRef();
        $typeIdOnly->recType->internalId = '314';
        $request = new AddListRequest();
        $request->record = [$this->customer(null, 'A'), $this->customer(null, 'B'), $typeIdOnly, $this->customer(null, 'C')];

        $list = (new WriteListHandler(new AddHandler($this->records), AddListResponse::class, 'record'))
            ->handle($request)->writeResponseList;

        $this->assertSuccess($list->writeResponse[0], '1', 'A', RecordType::customer);
        $this->assertFailure($list->writeResponse[1], StatusDetailCodeType::UNEXPECTED_ERROR, 'HTTP 500');
        $this->assertFailure($list->writeResponse[2], StatusDetailCodeType::USER_ERROR);
        $this->assertSuccess($list->writeResponse[3], '3', 'C', RecordType::customer);
        $this->assertCount(3, $this->transport->requests);
    }

    public function testAddListThrowsAFaultOnTheFirstItem()
    {
        $this->transport->push(new Response(500, [], '{"title":"Internal Server Error","status":500}'));
        $request = new AddListRequest();
        $request->record = [$this->customer(null, 'A'), $this->customer(null, 'B')];

        try {
            (new WriteListHandler(new AddHandler($this->records), AddListResponse::class, 'record'))->handle($request);
            $this->fail('RestFault expected');
        } catch (RestFault $fault) {
            $this->assertCount(1, $this->transport->requests);
        }
    }

    public function testAddListTurnsAnUnsupportedFirstItemIntoAStatus()
    {
        $this->transport->push($this->created('customer', '1'));
        $typeIdOnly = new CustomRecord();
        $typeIdOnly->recType = new RecordRef();
        $typeIdOnly->recType->internalId = '314';
        $request = new AddListRequest();
        $request->record = [$typeIdOnly, $this->customer(null, 'A')];

        $list = (new WriteListHandler(new AddHandler($this->records), AddListResponse::class, 'record'))
            ->handle($request)->writeResponseList;

        $this->assertFailure($list->writeResponse[0], StatusDetailCodeType::USER_ERROR);
        $this->assertSuccess($list->writeResponse[1], '1', 'A', RecordType::customer);
        $this->assertCount(1, $this->transport->requests);
    }

    public function testUpsertListAcceptsASingleRecord()
    {
        $this->transport->push($this->created('customer', '5'));
        $request = new UpsertListRequest();
        $request->record = $this->customer(null, 'E5');

        $response = (new WriteListHandler(new UpsertHandler($this->records), UpsertListResponse::class, 'record'))
            ->handle($request);

        $this->assertInstanceOf(UpsertListResponse::class, $response);
        $this->assertCount(1, $response->writeResponseList->writeResponse);
        $this->assertSuccess($response->writeResponseList->writeResponse[0], '5', 'E5', RecordType::customer);
    }

    /**
     * @dataProvider emptyListProvider
     */
    public function testEmptyListsSendNothing($items)
    {
        $request = new AddListRequest();
        $request->record = $items;

        $list = (new WriteListHandler(new AddHandler($this->records), AddListResponse::class, 'record'))
            ->handle($request)->writeResponseList;

        $this->assertTrue($list->status->isSuccess);
        $this->assertSame([], $list->writeResponse);
        $this->assertSame([], $this->transport->requests);
    }

    public static function emptyListProvider(): array
    {
        return ['null' => [null], 'empty array' => [[]]];
    }

    public function testDeleteListThroughTheFactory()
    {
        $this->transport->push(new Response(204, [], ''));
        $this->transport->push($this->error(404, 'not-found'));
        $request = new DeleteListRequest();
        $request->baseRef = [$this->ref(RecordType::customer, '1'), $this->ref(RecordType::salesOrder, '2')];
        $request->deletionReason = new DeletionReason();
        $handlers = HandlerFactory::create(function () {
            return $this->client;
        }, $this->logger);

        $response = $handlers['deleteList']()->handle($request);

        $this->assertInstanceOf(DeleteListResponse::class, $response);
        $list = $response->writeResponseList;
        $this->assertSuccess($list->writeResponse[0], '1', null, RecordType::customer);
        $this->assertFailure($list->writeResponse[1], StatusDetailCodeType::NONEXISTENT_ID);
        $this->assertSame(self::RECORD_URL.'/salesOrder/2', $this->request(1)->getUrl());
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('NetSuite REST: operation "deleteList" ignores deletionReason', $this->logger->records[0]['message']);
    }

    public function testFactoryBuildsHandlersLazilyOnOneClient()
    {
        $built = 0;
        $handlers = HandlerFactory::create(function () use (&$built) {
            $built++;
            return $this->client;
        });

        $this->assertSame([
            'get', 'getList', 'add', 'addList', 'update', 'updateList', 'upsert', 'upsertList', 'delete', 'deleteList',
        ], array_keys($handlers));
        $this->assertSame(0, $built);
        $expected = [
            'get' => GetHandler::class,
            'getList' => GetListHandler::class,
            'add' => AddHandler::class,
            'addList' => WriteListHandler::class,
            'update' => UpdateHandler::class,
            'updateList' => WriteListHandler::class,
            'upsert' => UpsertHandler::class,
            'upsertList' => WriteListHandler::class,
            'delete' => DeleteHandler::class,
            'deleteList' => WriteListHandler::class,
        ];
        foreach ($expected as $operation => $class) {
            $this->assertInstanceOf($class, $handlers[$operation]());
        }
        $this->assertSame($handlers['add'](), $handlers['add']());
        $this->assertSame(1, $built);
    }
}
