<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Record;

use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Record\RecordClient;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Http\CountingAuthenticator;
use tests\Netsuite\Rest\Http\FakeTransport;
use tests\Netsuite\Rest\Http\RecordingSleeper;

class RecordClientTest extends TestCase
{
    const BASE_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest';
    const RECORD_URL = self::BASE_URL.'/record/v1';

    /** @var FakeTransport */
    private $transport;
    /** @var RecordClient */
    private $records;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->records = new RecordClient(new RestClient(
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
        ));
    }

    private function lastRequest(): Request
    {
        $this->assertCount(1, $this->transport->requests);
        return $this->transport->requests[0];
    }

    private function created(string $type, string $id): Response
    {
        return new Response(204, ['Location' => self::RECORD_URL.'/'.$type.'/'.$id]);
    }

    private function fixture(int $status, string $name): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/vnd.oracle.resource+json; type=error'],
            (string) file_get_contents(__DIR__.'/../Fixtures/errors/'.$name.'.json')
        );
    }

    private function catchError(callable $call): RestError
    {
        try {
            $call();
        } catch (RestError $error) {
            return $error;
        }
        $this->fail('RestError was not thrown');
    }

    public function testGetByInternalIdExpandsSubResources()
    {
        $this->transport->push(new Response(200, [], '{"id":"42","companyName":"Acme","links":[]}'));

        $record = $this->records->get('customer', '42');

        $this->assertSame(['id' => '42', 'companyName' => 'Acme', 'links' => []], $record);
        $request = $this->lastRequest();
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer/42?expandSubResources=true', $request->getUrl());
        $this->assertNull($request->getBody());
    }

    public function testGetByExternalId()
    {
        $this->transport->push(new Response(200, [], '{"id":"42"}'));

        $this->records->get('salesOrder', RecordClient::externalId('SO-2024_01'));

        $this->assertSame(
            self::RECORD_URL.'/salesOrder/eid:SO-2024_01?expandSubResources=true',
            $this->lastRequest()->getUrl()
        );
    }

    public function testGetWithNonJsonBodyIsRestError()
    {
        $this->transport->push(new Response(200, [], 'not json'));

        $error = $this->catchError(function () {
            $this->records->get('customer', '42');
        });

        $this->assertSame('UNEXPECTED_ERROR', $error->getDetails()[0]->getErrorCode());
    }

    public function testCreatePostsBodyAndReturnsIdFromLocation()
    {
        $this->transport->push($this->created('customer', '1234'));

        $id = $this->records->create('customer', ['companyName' => 'Acme', 'subsidiary' => ['id' => '1']]);

        $this->assertSame('1234', $id);
        $request = $this->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer', $request->getUrl());
        $this->assertSame('{"companyName":"Acme","subsidiary":{"id":"1"}}', $request->getBody());
        $this->assertSame('application/json', $request->getHeader('Content-Type'));
    }

    public function testCreateWithoutLocationIsRestError()
    {
        $this->transport->push(new Response(204));

        $error = $this->catchError(function () {
            $this->records->create('customer', ['companyName' => 'Acme']);
        });

        $this->assertSame(204, $error->getHttpStatus());
        $this->assertSame('UNEXPECTED_ERROR', $error->getDetails()[0]->getErrorCode());
    }

    public function testUpdatePatchesWithReplaceList()
    {
        $this->transport->push($this->created('salesOrder', '77'));

        $id = $this->records->update('salesOrder', '77', ['memo' => null, 'item' => ['items' => []]], ['item', 'salesTeam']);

        $this->assertSame('77', $id);
        $request = $this->lastRequest();
        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/salesOrder/77?replace=item%2CsalesTeam', $request->getUrl());
        $this->assertSame('{"memo":null,"item":{"items":[]}}', $request->getBody());
    }

    public function testUpdateWithoutReplaceHasNoQueryAndToleratesMissingLocation()
    {
        $this->transport->push(new Response(204));

        $id = $this->records->update('customer', RecordClient::externalId('C1'), ['comments' => 'x']);

        $this->assertNull($id);
        $this->assertSame(self::RECORD_URL.'/customer/eid:C1', $this->lastRequest()->getUrl());
    }

    public function testUpsertPutsByExternalIdAndReturnsInternalId()
    {
        $this->transport->push($this->created('customer', '501'));

        $id = $this->records->upsert('customer', 'CUST-1', ['companyName' => 'Acme']);

        $this->assertSame('501', $id);
        $request = $this->lastRequest();
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/customer/eid:CUST-1', $request->getUrl());
        $this->assertSame('{"companyName":"Acme"}', $request->getBody());
    }

    public function testDeleteSendsNoBody()
    {
        $this->transport->push(new Response(204));

        $this->records->delete('customrecord_widget', '9');

        $request = $this->lastRequest();
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame(self::RECORD_URL.'/customrecord_widget/9', $request->getUrl());
        $this->assertNull($request->getBody());
    }

    public function testNegativeInternalIdIsAccepted()
    {
        $this->transport->push(new Response(200, [], '{"id":"-5"}'));

        $this->records->get('employee', '-5');

        $this->assertSame(self::RECORD_URL.'/employee/-5?expandSubResources=true', $this->lastRequest()->getUrl());
    }

    /**
     * @dataProvider invalidIds
     */
    public function testInvalidIdIsRejectedWithoutRequest(string $id)
    {
        $error = $this->catchError(function () use ($id) {
            $this->records->delete('customer', $id);
        });

        $this->assertSame(400, $error->getHttpStatus());
        $this->assertSame('INVALID_KEY_OR_REF', $error->getDetails()[0]->getErrorCode());
        $this->assertSame([], $this->transport->requests);
    }

    public function invalidIds(): array
    {
        return [
            'empty'                   => [''],
            'non-numeric internal id' => ['abc'],
            'path traversal'          => ['1/../2'],
            'empty external id'       => ['eid:'],
            'space'                   => ['eid:A B'],
            'dot'                     => ['eid:a.b'],
            'slash'                   => ['eid:a/b'],
            'unicode'                 => ['eid:Ünï'],
        ];
    }

    public function testInvalidExternalIdOnUpsertIsRejected()
    {
        $error = $this->catchError(function () {
            $this->records->upsert('customer', 'a?b', []);
        });

        $this->assertSame('INVALID_KEY_OR_REF', $error->getDetails()[0]->getErrorCode());
        $this->assertSame([], $this->transport->requests);
    }

    public function testInvalidTypeThrows()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->records->get('customer/1', '2');
    }

    public function testNotFoundSurfacesAsRestError()
    {
        $this->transport->push($this->fixture(404, 'not-found'));

        $error = $this->catchError(function () {
            $this->records->get('customer', '999999');
        });

        $this->assertSame(404, $error->getHttpStatus());
        $this->assertSame('NONEXISTENT_ID', $error->getDetails()[0]->getErrorCode());
        $this->assertStringContainsString('does not exist', $error->getMessage());
    }

    public function testInvalidContentSurfacesAsRestError()
    {
        $this->transport->push($this->fixture(400, 'invalid-content'));

        $error = $this->catchError(function () {
            $this->records->create('customer', ['subsidiary' => ['id' => '999']]);
        });

        $this->assertSame(400, $error->getHttpStatus());
        $this->assertCount(2, $error->getDetails());
        $this->assertSame('INVALID_CONTENT', $error->getDetails()[0]->getErrorCode());
        $this->assertSame('subsidiary', $error->getDetails()[0]->getErrorPath());
    }

    public function testServerErrorStaysRestFault()
    {
        $this->transport->push(new Response(503, [], 'Service Unavailable'));

        $this->expectException(RestFault::class);

        $this->records->delete('customer', '1');
    }
}
