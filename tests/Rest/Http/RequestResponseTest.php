<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;

class RequestResponseTest extends TestCase
{
    public function testRequestBasics()
    {
        $request = new Request('patch', 'https://example.test/x', ['Content-Type' => 'application/json'], '{}');

        $this->assertSame('PATCH', $request->getMethod());
        $this->assertSame('https://example.test/x', $request->getUrl());
        $this->assertSame('{}', $request->getBody());
        $this->assertSame(['Content-Type: application/json'], $request->getHeaderLines());
    }

    public function testUnsupportedMethod()
    {
        $this->expectException(\InvalidArgumentException::class);
        new Request('HEAD', 'https://example.test/');
    }

    public function testHeaderNamesAreCaseInsensitive()
    {
        $request = new Request('GET', 'https://example.test/', ['Content-Type' => 'application/json']);

        $this->assertSame('application/json', $request->getHeader('content-type'));
        $this->assertSame('application/json', $request->getHeader('CONTENT-TYPE'));
        $this->assertTrue($request->hasHeader('Content-type'));
        $this->assertNull($request->getHeader('Accept'));
        $this->assertFalse($request->hasHeader('Accept'));
    }

    public function testWithHeaderReplacesAnySpellingAndIsImmutable()
    {
        $request = new Request('GET', 'https://example.test/', ['authorization' => 'old']);
        $signed = $request->withHeader('Authorization', 'new');

        $this->assertSame('old', $request->getHeader('Authorization'));
        $this->assertSame(['Authorization' => 'new'], $signed->getHeaders());
    }

    public function testResponseHeadersAndJson()
    {
        $response = new Response(204, ['location' => 'https://example.test/record/v1/customer/42']);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('https://example.test/record/v1/customer/42', $response->getHeader('Location'));
        $this->assertNull($response->json());
    }

    public function testResponseJson()
    {
        $this->assertSame(['id' => '1'], (new Response(200, [], '{"id":"1"}'))->json());
        $this->assertNull((new Response(200, [], 'not json'))->json());
        $this->assertNull((new Response(200, [], '"scalar"'))->json());
        $this->assertFalse((new Response(404))->isSuccessful());
        $this->assertFalse((new Response(199))->isSuccessful());
    }
}
