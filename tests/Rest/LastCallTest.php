<?php

namespace tests\Netsuite\Rest;

use NetSuite\Rest\Http\CallRecorder;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\LastCall;
use PHPUnit\Framework\TestCase;

class LastCallTest extends TestCase
{
    public function testEmpty()
    {
        $lastCall = new LastCall(new CallRecorder(), function () {
            return null;
        });

        $this->assertNull($lastCall->__getLastRequest());
        $this->assertNull($lastCall->__getLastResponse());
        $this->assertNull($lastCall->__getLastRequestHeaders());
        $this->assertNull($lastCall->__getLastResponseHeaders());
    }

    public function testRestCall()
    {
        $recorder = new CallRecorder();
        $lastCall = new LastCall($recorder, function () {
            $this->fail('the SOAP client is not used for a REST call');
        });
        $lastCall->startRest();
        $recorder->record(
            new Request('PATCH', 'https://example.test/record/v1/customer/7', ['Content-Type' => 'application/json'], '{"a":1}'),
            new Response(204, ['Location' => 'https://example.test/record/v1/customer/7'])
        );

        $this->assertSame('{"a":1}', $lastCall->__getLastRequest());
        $this->assertSame('', $lastCall->__getLastResponse());
        $this->assertSame("PATCH https://example.test/record/v1/customer/7 HTTP/1.1\r\nContent-Type: application/json", $lastCall->__getLastRequestHeaders());
        $this->assertSame("HTTP/1.1 204\r\nLocation: https://example.test/record/v1/customer/7", $lastCall->__getLastResponseHeaders());
    }

    public function testStartRestClearsThePreviousCall()
    {
        $recorder = new CallRecorder();
        $recorder->record(new Request('GET', 'https://example.test/x'), new Response(200, [], '{}'));
        $lastCall = new LastCall($recorder, function () {
            return null;
        });

        $lastCall->startRest();

        $this->assertNull($lastCall->__getLastRequestHeaders());
        $this->assertNull($lastCall->__getLastResponse());
    }

    public function testSoapCall()
    {
        $soap = $this->createMock(\SoapClient::class);
        $soap->method('__getLastRequest')->willReturn('<request/>');
        $soap->method('__getLastResponse')->willReturn('<response/>');
        $soap->method('__getLastRequestHeaders')->willReturn('POST /services/NetSuitePort_2025_2');
        $soap->method('__getLastResponseHeaders')->willReturn('HTTP/1.1 200 OK');
        $recorder = new CallRecorder();
        $recorder->record(new Request('GET', 'https://example.test/x'), new Response(200, [], '{}'));
        $lastCall = new LastCall($recorder, function () use ($soap) {
            return $soap;
        });

        $lastCall->startSoap();

        $this->assertSame('<request/>', $lastCall->__getLastRequest());
        $this->assertSame('<response/>', $lastCall->__getLastResponse());
        $this->assertSame('POST /services/NetSuitePort_2025_2', $lastCall->__getLastRequestHeaders());
        $this->assertSame('HTTP/1.1 200 OK', $lastCall->__getLastResponseHeaders());
    }

    public function testSoapCallWithoutClient()
    {
        $recorder = new CallRecorder();
        $recorder->record(new Request('GET', 'https://example.test/x'), new Response(200, [], '{}'));
        $lastCall = new LastCall($recorder, function () {
            return null;
        });

        $lastCall->startSoap();

        $this->assertNull($lastCall->__getLastRequest());
        $this->assertNull($lastCall->__getLastResponseHeaders());
    }
}
