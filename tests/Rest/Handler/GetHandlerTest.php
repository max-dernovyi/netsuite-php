<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Handler;

use NetSuite\Classes\CustomRecord;
use NetSuite\Classes\CustomRecordRef;
use NetSuite\Classes\Customer;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\GetListResponse;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Handler\GetHandler;
use NetSuite\Rest\Handler\GetListHandler;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Record\RecordClient;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Http\CountingAuthenticator;
use tests\Netsuite\Rest\Http\FakeTransport;
use tests\Netsuite\Rest\Http\RecordingSleeper;

class GetHandlerTest extends TestCase
{
    const RECORD_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/record/v1';

    /** @var FakeTransport */
    private $transport;
    /** @var RestClient */
    private $client;
    /** @var GetHandler */
    private $get;

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
        $this->get = new GetHandler(new RecordClient($this->client));
    }

    private function json(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/vnd.oracle.resource+json; type=singular-resource'], $body);
    }

    private function record(string $name): Response
    {
        return $this->json((string) file_get_contents(__DIR__.'/../Fixtures/records/'.$name.'.json'));
    }

    private function error(int $status, string $name): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/vnd.oracle.resource+json; type=error'],
            (string) file_get_contents(__DIR__.'/../Fixtures/errors/'.$name.'.json')
        );
    }

    private function ref(string $type, ?string $internalId, ?string $externalId = null): RecordRef
    {
        $ref = new RecordRef();
        $ref->type = $type;
        $ref->internalId = $internalId;
        $ref->externalId = $externalId;
        return $ref;
    }

    private function getRequest($ref): GetRequest
    {
        $request = new GetRequest();
        $request->baseRef = $ref;
        return $request;
    }

    /**
     * @return Request[]
     */
    private function requests(): array
    {
        return $this->transport->requests;
    }

    private function assertFailure(ReadResponse $read, string $code, ?string $message = null): void
    {
        $this->assertFalse($read->status->isSuccess);
        $this->assertNull($read->record);
        $this->assertCount(1, $read->status->statusDetail);
        $this->assertSame($code, $read->status->statusDetail[0]->code);
        $this->assertSame('ERROR', $read->status->statusDetail[0]->type);
        if ($message !== null) {
            $this->assertStringContainsString($message, $read->status->statusDetail[0]->message);
        }
    }

    public function testGetByInternalId()
    {
        $this->transport->push($this->record('customer'));

        $response = $this->get->handle($this->getRequest($this->ref(RecordType::customer, '107')));

        $this->assertInstanceOf(GetResponse::class, $response);
        $this->assertTrue($response->readResponse->status->isSuccess);
        $this->assertInstanceOf(Customer::class, $response->readResponse->record);
        $this->assertSame('107', $response->readResponse->record->internalId);
        $request = $this->requests()[0];
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer/107?expandSubResources=true', $request->getUrl());
    }

    public function testInternalIdWinsOverExternalId()
    {
        $this->transport->push($this->json('{"id":"107"}'));

        $this->get->handle($this->getRequest($this->ref(RecordType::customer, '107', 'CUST_107')));

        $this->assertSame(self::RECORD_URL.'/customer/107?expandSubResources=true', $this->requests()[0]->getUrl());
    }

    public function testGetByExternalId()
    {
        $this->transport->push($this->record('salesOrder'));

        $read = $this->get->handle($this->getRequest($this->ref(RecordType::salesOrder, null, 'SO-5012')))->readResponse;

        $this->assertSame(self::RECORD_URL.'/salesOrder/eid:SO-5012?expandSubResources=true', $this->requests()[0]->getUrl());
        $this->assertTrue($read->status->isSuccess);
        $this->assertInstanceOf(SalesOrder::class, $read->record);
        $this->assertSame('5012', $read->record->internalId);
        $this->assertNotEmpty($read->record->itemList->item);
    }

    public function testRecordTypeIsCaseInsensitive()
    {
        $this->transport->push($this->json('{"id":"5"}'));

        $read = $this->get->read($this->ref('SALESORDER', '5'));

        $this->assertInstanceOf(SalesOrder::class, $read->record);
        $this->assertSame(self::RECORD_URL.'/salesOrder/5?expandSubResources=true', $this->requests()[0]->getUrl());
    }

    public function testCustomRecordByScriptId()
    {
        $this->transport->push($this->json(
            '{"id":"9","name":"Blue widget","externalId":"W-9","custrecord_colour":"blue","links":[]}'
        ));
        $ref = new CustomRecordRef();
        $ref->internalId = '9';
        $ref->typeId = '314';
        $ref->scriptId = 'customrecord_widget';

        $read = $this->get->handle($this->getRequest($ref))->readResponse;

        $this->assertSame(self::RECORD_URL.'/customrecord_widget/9?expandSubResources=true', $this->requests()[0]->getUrl());
        $this->assertTrue($read->status->isSuccess);
        $record = $read->record;
        $this->assertInstanceOf(CustomRecord::class, $record);
        $this->assertSame('9', $record->internalId);
        $this->assertSame('W-9', $record->externalId);
        $this->assertSame('Blue widget', $record->name);
        $this->assertSame('314', $record->recType->internalId);
        $field = $record->customFieldList->customField[0];
        $this->assertInstanceOf(StringCustomFieldRef::class, $field);
        $this->assertSame('custrecord_colour', $field->scriptId);
        $this->assertSame('blue', $field->value);
    }

    public function testCustomRecordByExternalIdWithScriptIdTypeHasNoRecType()
    {
        $this->transport->push($this->json('{"id":"9"}'));
        $ref = new CustomRecordRef();
        $ref->externalId = 'W-9';
        $ref->typeId = 'customrecord_widget';

        $read = $this->get->read($ref);

        $this->assertSame(self::RECORD_URL.'/customrecord_widget/eid:W-9?expandSubResources=true', $this->requests()[0]->getUrl());
        $this->assertNull($read->record->recType);
    }

    public function testCustomRecordKnownOnlyByTypeIdIsNotSupported()
    {
        $ref = new CustomRecordRef();
        $ref->internalId = '9';
        $ref->typeId = '314';

        $this->expectException(NotSupportedOnRestException::class);
        $this->get->read($ref);
    }

    public function testNotFoundBecomesAStatus()
    {
        $this->transport->push($this->error(404, 'not-found'));

        $read = $this->get->handle($this->getRequest($this->ref(RecordType::customer, '999')))->readResponse;

        $this->assertFailure($read, StatusDetailCodeType::NONEXISTENT_ID, 'The record instance does not exist');
    }

    public function testInvalidExternalIdBecomesAStatusWithoutARequest()
    {
        $read = $this->get->read($this->ref(RecordType::customer, null, 'bad id'));

        $this->assertFailure($read, StatusDetailCodeType::INVALID_KEY_OR_REF, 'Invalid external id');
        $this->assertSame([], $this->requests());
    }

    /**
     * @dataProvider invalidRefProvider
     */
    public function testInvalidReferencesBecomeStatuses($ref, string $code)
    {
        $this->assertFailure($this->get->read($ref), $code);
        $this->assertSame([], $this->requests());
    }

    public static function invalidRefProvider(): array
    {
        $noType = new RecordRef();
        $noType->internalId = '1';
        $unknown = new RecordRef();
        $unknown->type = 'nope';
        $unknown->internalId = '1';
        $notReadable = new RecordRef();
        $notReadable->type = RecordType::customTransactionType;
        $notReadable->internalId = '1';
        $noKey = new RecordRef();
        $noKey->type = RecordType::customer;

        return [
            'null'                => [null, StatusDetailCodeType::INVALID_KEY_OR_REF],
            'not a ref'           => [new Customer(), StatusDetailCodeType::INVALID_KEY_OR_REF],
            'no type'             => [$noType, StatusDetailCodeType::INVALID_RCRD_TYPE],
            'unknown type'        => [$unknown, StatusDetailCodeType::INVALID_RCRD_TYPE],
            'type without class'  => [$notReadable, StatusDetailCodeType::INVALID_RCRD_TYPE],
            'no internal or eid'  => [$noKey, StatusDetailCodeType::INVALID_KEY_OR_REF],
        ];
    }

    public function testFaultsAreThrown()
    {
        $this->transport->push(new Response(401, [], '{"title":"Unauthorized","status":401}'));
        $this->transport->push(new Response(401, [], '{"title":"Unauthorized","status":401}'));

        $this->expectException(RestFault::class);
        $this->get->read($this->ref(RecordType::customer, '1'));
    }

    public function testGetListKeepsOrderWithMixedTypesAndAFailingItem()
    {
        $this->transport->push($this->record('customer'));
        $this->transport->push($this->error(404, 'not-found'));
        $this->transport->push($this->json('{"id":"5012"}'));
        $this->transport->push($this->json('{"id":"31","itemId":"WIDGET"}'));
        $request = new GetListRequest();
        $request->baseRef = [
            $this->ref(RecordType::customer, '107'),
            $this->ref(RecordType::customer, '999'),
            $this->ref(RecordType::salesOrder, null, 'SO-5012'),
            $this->ref(RecordType::customer, null),
            $this->ref(RecordType::inventoryItem, '31'),
        ];

        $response = (new GetListHandler($this->get))->handle($request);

        $this->assertInstanceOf(GetListResponse::class, $response);
        $list = $response->readResponseList;
        $this->assertTrue($list->status->isSuccess);
        $this->assertCount(5, $list->readResponse);
        $this->assertInstanceOf(Customer::class, $list->readResponse[0]->record);
        $this->assertFailure($list->readResponse[1], StatusDetailCodeType::NONEXISTENT_ID);
        $this->assertInstanceOf(SalesOrder::class, $list->readResponse[2]->record);
        $this->assertFailure($list->readResponse[3], StatusDetailCodeType::INVALID_KEY_OR_REF);
        $this->assertInstanceOf(InventoryItem::class, $list->readResponse[4]->record);
        $this->assertSame('WIDGET', $list->readResponse[4]->record->itemId);
        $this->assertSame([
            self::RECORD_URL.'/customer/107?expandSubResources=true',
            self::RECORD_URL.'/customer/999?expandSubResources=true',
            self::RECORD_URL.'/salesOrder/eid:SO-5012?expandSubResources=true',
            self::RECORD_URL.'/inventoryItem/31?expandSubResources=true',
        ], array_map(function (Request $request) {
            return $request->getUrl();
        }, $this->requests()));
    }

    public function testGetListTurnsFaultsAfterTheFirstItemIntoStatuses()
    {
        $this->transport->push($this->record('customer'));
        $this->transport->push(new Response(500, [], '{"title":"Internal Server Error","status":500}'));
        $this->transport->push($this->json('{"id":"31","itemId":"WIDGET"}'));
        $typeIdOnly = new CustomRecordRef();
        $typeIdOnly->internalId = '9';
        $typeIdOnly->typeId = '314';
        $request = new GetListRequest();
        $request->baseRef = [
            $this->ref(RecordType::customer, '107'),
            $this->ref(RecordType::customer, '108'),
            $typeIdOnly,
            $this->ref(RecordType::inventoryItem, '31'),
        ];

        $list = (new GetListHandler($this->get))->handle($request)->readResponseList;

        $this->assertInstanceOf(Customer::class, $list->readResponse[0]->record);
        $this->assertFailure($list->readResponse[1], StatusDetailCodeType::UNEXPECTED_ERROR, 'HTTP 500');
        $this->assertFailure($list->readResponse[2], StatusDetailCodeType::USER_ERROR);
        $this->assertInstanceOf(InventoryItem::class, $list->readResponse[3]->record);
        $this->assertCount(3, $this->requests());
    }

    public function testGetListThrowsAFaultOnTheFirstItem()
    {
        $this->transport->push(new Response(500, [], '{"title":"Internal Server Error","status":500}'));
        $request = new GetListRequest();
        $request->baseRef = [$this->ref(RecordType::customer, '107'), $this->ref(RecordType::customer, '108')];

        try {
            (new GetListHandler($this->get))->handle($request);
            $this->fail('RestFault expected');
        } catch (RestFault $fault) {
            $this->assertCount(1, $this->requests());
        }
    }

    public function testGetListTurnsAnUnsupportedFirstItemIntoAStatus()
    {
        $this->transport->push($this->record('customer'));
        $request = new GetListRequest();
        $typeIdOnly = new CustomRecordRef();
        $typeIdOnly->internalId = '9';
        $typeIdOnly->typeId = '314';
        $request->baseRef = [$typeIdOnly, $this->ref(RecordType::customer, '107')];

        $list = (new GetListHandler($this->get))->handle($request)->readResponseList;

        $this->assertFailure($list->readResponse[0], StatusDetailCodeType::USER_ERROR);
        $this->assertInstanceOf(Customer::class, $list->readResponse[1]->record);
        $this->assertCount(1, $this->requests());
    }

    /**
     * @dataProvider emptyListProvider
     */
    public function testGetListWithoutReferences($refs)
    {
        $request = new GetListRequest();
        $request->baseRef = $refs;

        $list = (new GetListHandler($this->get))->handle($request)->readResponseList;

        $this->assertTrue($list->status->isSuccess);
        $this->assertSame([], $list->readResponse);
        $this->assertSame([], $this->requests());
    }

    public static function emptyListProvider(): array
    {
        return ['null' => [null], 'empty array' => [[]]];
    }

    public function testGetListAcceptsASingleReference()
    {
        $this->transport->push($this->json('{"id":"1"}'));
        $request = new GetListRequest();
        $request->baseRef = $this->ref(RecordType::customer, '1');

        $list = (new GetListHandler($this->get))->handle($request)->readResponseList;

        $this->assertCount(1, $list->readResponse);
        $this->assertSame('1', $list->readResponse[0]->record->internalId);
    }
}
