<?php

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Http\CallRecorder;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;

class CallRecorderTest extends TestCase
{
    public function testEmptyRecorder()
    {
        $recorder = new CallRecorder();

        $this->assertNull($recorder->getLastRequest());
        $this->assertNull($recorder->getLastRequestHeaders());
        $this->assertNull($recorder->getLastRequestBody());
        $this->assertNull($recorder->getLastResponse());
        $this->assertNull($recorder->getLastResponseHeaders());
        $this->assertNull($recorder->getLastResponseBody());
    }

    public function testRecordsAndClears()
    {
        $recorder = new CallRecorder();
        $request = new Request('POST', 'https://example.test/record/v1/customer', ['Content-Type' => 'application/json'], '{"a":1}');
        $response = new Response(204, ['Location' => 'https://example.test/record/v1/customer/7']);

        $recorder->record($request, $response);

        $this->assertSame($request, $recorder->getLastRequest());
        $this->assertSame("POST https://example.test/record/v1/customer HTTP/1.1\r\nContent-Type: application/json", $recorder->getLastRequestHeaders());
        $this->assertSame('{"a":1}', $recorder->getLastRequestBody());
        $this->assertSame("HTTP/1.1 204\r\nLocation: https://example.test/record/v1/customer/7", $recorder->getLastResponseHeaders());
        $this->assertSame('', $recorder->getLastResponseBody());

        $recorder->clear();

        $this->assertNull($recorder->getLastRequest());
        $this->assertNull($recorder->getLastResponse());
    }
}
