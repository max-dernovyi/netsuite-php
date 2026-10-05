<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;

class RestClientTest extends TestCase
{
    const BASE_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest';
    const NOW = 1700000000;

    /** @var FakeTransport */
    private $transport;
    /** @var CountingAuthenticator */
    private $auth;
    /** @var RecordingSleeper */
    private $sleeper;
    /** @var SpyLogger */
    private $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->auth = new CountingAuthenticator();
        $this->sleeper = new RecordingSleeper();
        $this->logger = new SpyLogger();
    }

    private function client(array $config = [], float $random = 0.0): RestClient
    {
        return new RestClient(
            RestConfig::fromArray($config + [
                'transport'      => 'rest',
                'account'        => '123456_SB1',
                'consumerKey'    => 'ck',
                'consumerSecret' => 'cs',
                'token'          => 't',
                'tokenSecret'    => 'ts',
            ]),
            $this->transport,
            $this->auth,
            $this->logger,
            $this->sleeper,
            null,
            function () use ($random) {
                return $random;
            },
            function () {
                return self::NOW;
            }
        );
    }

    private function queue(Response ...$responses): void
    {
        foreach ($responses as $response) {
            $this->transport->push($response);
        }
    }

    private function catchFault(callable $call): RestFault
    {
        try {
            $call();
        } catch (RestFault $fault) {
            return $fault;
        }
        $this->fail('RestFault was not thrown');
    }

    public function testBuildsUrlHeadersAndJsonBody()
    {
        $this->queue(new Response(204, ['Location' => self::BASE_URL.'/record/v1/customer/7']));

        $response = $this->client()->send('post', '/record/v1/customer', [
            'replace'            => ['addressBook', 'contactRoles'],
            'expandSubResources' => true,
            'skip'               => null,
            'q'                  => 'a b&c',
        ], ['companyName' => 'Acme / Ltd', 'rate' => 1.0, 'name' => 'Ünïcode']);

        $this->assertSame(204, $response->getStatusCode());
        $request = $this->transport->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(
            self::BASE_URL.'/record/v1/customer?replace=addressBook%2CcontactRoles&expandSubResources=true&q=a%20b%26c',
            $request->getUrl()
        );
        $this->assertSame('application/json', $request->getHeader('Accept'));
        $this->assertSame('application/json', $request->getHeader('Content-Type'));
        $this->assertSame('Bearer token-1', $request->getHeader('Authorization'));
        $this->assertSame('{"companyName":"Acme / Ltd","rate":1.0,"name":"Ünïcode"}', $request->getBody());
    }

    public function testGetWithoutBodyHasNoContentType()
    {
        $this->queue(new Response(200, [], '{"id":"42"}'));

        $this->client()->send('GET', 'record/v1/customer/42');

        $request = $this->transport->requests[0];
        $this->assertSame(self::BASE_URL.'/record/v1/customer/42', $request->getUrl());
        $this->assertNull($request->getBody());
        $this->assertFalse($request->hasHeader('Content-Type'));
    }

    public function testEmptyJsonIsAnObjectAndCallerHeadersWin()
    {
        $this->queue(new Response(204));

        $this->client()->send('PATCH', '/record/v1/customer/42', [], [], ['content-type' => 'application/merge+json', 'Prefer' => 'transient']);

        $request = $this->transport->requests[0];
        $this->assertSame('{}', $request->getBody());
        $this->assertSame('application/merge+json', $request->getHeader('Content-Type'));
        $this->assertSame('transient', $request->getHeader('Prefer'));
    }

    public function testRestBaseUrlOverride()
    {
        $this->queue(new Response(200));

        $this->client(['restBaseUrl' => 'http://127.0.0.1:8080/rest/'])->send('GET', '/record/v1/customer/1');

        $this->assertSame('http://127.0.0.1:8080/rest/record/v1/customer/1', $this->transport->requests[0]->getUrl());
    }

    public function testUnencodableJsonIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->client()->send('POST', '/record/v1/customer', [], ['name' => "\xB1\x31"]);
    }

    public function testGet500IsRetriedWithFreshSignature()
    {
        $this->queue(new Response(500, [], '{"title":"Internal"}'), new Response(200, [], '{"id":"42"}'));

        $response = $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->transport->requests);
        $this->assertSame('Bearer token-1', $this->transport->requests[0]->getHeader('Authorization'));
        $this->assertSame('Bearer token-2', $this->transport->requests[1]->getHeader('Authorization'));
        $this->assertSame([0.25], $this->sleeper->delays);
    }

    public function testBackoffGrowsExponentiallyWithJitter()
    {
        $this->queue(new Response(503), new Response(503), new Response(503), new Response(200));

        $this->client(['maxAttempts' => 4], 0.5)->send('DELETE', '/record/v1/customer/42');

        // delay = d/2 + random * d/2 with d = 0.5 * 2^(attempt-1)
        $this->assertSame([0.375, 0.75, 1.5], $this->sleeper->delays);
    }

    public function testBackoffIsCapped()
    {
        $this->queue(...array_fill(0, 9, new Response(502)));
        $this->queue(new Response(200));

        $this->client(['maxAttempts' => 10], 0.0)->send('PUT', '/record/v1/customer/eid:A1', [], ['x' => 1]);

        $this->assertSame(RestClient::BACKOFF_MAX / 2, end($this->sleeper->delays));
    }

    /**
     * @dataProvider nonReplaySafeMethods
     */
    public function testPostAndPatch500AreSentOnce(string $method)
    {
        $this->queue(new Response(500, [], '{"title":"Internal","o:errorDetails":[{"detail":"Boom"}]}'));

        $fault = $this->catchFault(function () use ($method) {
            $this->client()->send($method, '/record/v1/customer/42', [], ['x' => 1]);
        });

        $this->assertCount(1, $this->transport->requests);
        $this->assertSame([], $this->sleeper->delays);
        $this->assertSame(RestFault::UNEXPECTED_ERROR, $fault->_name);
        $this->assertSame(500, $fault->getHttpStatus());
        $this->assertStringContainsString('HTTP 500: Boom', $fault->getMessage());
        $this->assertStringNotContainsString('attempts', $fault->getMessage());
    }

    public function nonReplaySafeMethods(): array
    {
        return [['POST'], ['PATCH']];
    }

    public function testAttemptsExhausted()
    {
        $this->queue(new Response(503), new Response(503), new Response(503));

        $fault = $this->catchFault(function () {
            $this->client()->send('GET', '/record/v1/customer/42');
        });

        $this->assertCount(3, $this->transport->requests);
        $this->assertCount(2, $this->sleeper->delays);
        $this->assertSame(RestFault::UNEXPECTED_ERROR, $fault->_name);
        $this->assertSame(503, $fault->getHttpStatus());
        $this->assertStringContainsString('GET '.self::BASE_URL.'/record/v1/customer/42 failed after 3 attempts', $fault->getMessage());
        $this->assertInstanceOf(\SoapFault::class, $fault);
    }

    public function test429WaitsRetryAfterSeconds()
    {
        $this->queue(new Response(429, ['Retry-After' => '7']), new Response(200));

        $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame([7.0], $this->sleeper->delays);
        $this->assertCount(2, $this->transport->requests);
    }

    public function test429WaitsRetryAfterHttpDate()
    {
        $this->queue(new Response(429, ['retry-after' => gmdate('D, d M Y H:i:s \G\M\T', self::NOW + 12)]), new Response(200));

        $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame([12.0], $this->sleeper->delays);
    }

    public function test429RetryAfterInThePastRetriesImmediately()
    {
        $this->queue(new Response(429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', self::NOW - 5)]), new Response(200));

        $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame([0.0], $this->sleeper->delays);
    }

    public function test429WithoutOrUnreadableRetryAfterBacksOff()
    {
        $this->queue(new Response(429), new Response(429, ['Retry-After' => 'soon']), new Response(200));

        $this->client([], 0.0)->send('GET', '/record/v1/customer/42');

        $this->assertSame([0.25, 0.5], $this->sleeper->delays);
    }

    public function test429WithLongRetryAfterFailsWithoutWaiting()
    {
        $this->queue(new Response(429, ['Retry-After' => '3600']));

        $fault = $this->catchFault(function () {
            $this->client()->send('GET', '/record/v1/customer/42');
        });

        $this->assertSame([], $this->sleeper->delays);
        $this->assertSame(RestFault::EXCEEDED_CONCURRENT_REQUEST_LIMIT, $fault->_name);
        $this->assertSame(429, $fault->getHttpStatus());
    }

    public function test429OnPostIsNotRetried()
    {
        $this->queue(new Response(429, ['Retry-After' => '1']));

        $fault = $this->catchFault(function () {
            $this->client()->send('POST', '/record/v1/customer', [], ['x' => 1]);
        });

        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(RestFault::EXCEEDED_CONCURRENT_REQUEST_LIMIT, $fault->_name);
    }

    public function testTransportErrorOnGetIsRetried()
    {
        $this->transport->push(new TransportException('cURL error 28: timeout'));
        $this->queue(new Response(200));

        $response = $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->transport->requests);
        $this->assertCount(1, $this->sleeper->delays);
    }

    public function testTransportErrorsExhausted()
    {
        for ($i = 0; $i < 3; $i++) {
            $this->transport->push(new TransportException('cURL error 7: refused'));
        }

        $fault = $this->catchFault(function () {
            $this->client()->send('DELETE', '/record/v1/customer/42');
        });

        $this->assertCount(3, $this->transport->requests);
        $this->assertSame(RestFault::UNEXPECTED_ERROR, $fault->_name);
        $this->assertNull($fault->getHttpStatus());
        $this->assertStringContainsString('after 3 attempts: cURL error 7: refused', $fault->getMessage());
    }

    public function testTransportErrorOnPostIsNotRetried()
    {
        $this->transport->push(new TransportException('cURL error 28: timeout'));

        $fault = $this->catchFault(function () {
            $this->client()->send('POST', '/record/v1/customer', [], ['x' => 1]);
        });

        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(RestFault::UNEXPECTED_ERROR, $fault->_name);
    }

    public function testTokenEndpointTransportErrorIsRetriedForGet()
    {
        $this->auth->failures[] = new TransportException('token endpoint unreachable');
        $this->queue(new Response(200));

        $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertCount(1, $this->transport->requests);
        $this->assertCount(1, $this->sleeper->delays);
    }

    public function testAuthFaultPassesThrough()
    {
        $this->auth->failures[] = RestFault::invalidCredentials('invalid_client');

        $fault = $this->catchFault(function () {
            $this->client()->send('GET', '/record/v1/customer/42');
        });

        $this->assertSame('invalid_client', $fault->getMessage());
        $this->assertSame([], $this->transport->requests);
    }

    /**
     * @dataProvider allMethods
     */
    public function test401InvalidatesAndResendsOnce(string $method)
    {
        $this->queue(new Response(401, [], '{"title":"Unauthorized"}'), new Response(204));

        $response = $this->client(['maxAttempts' => 1])->send($method, '/record/v1/customer/42');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(1, $this->auth->invalidated);
        $this->assertCount(2, $this->transport->requests);
        $this->assertSame('Bearer token-2', $this->transport->requests[1]->getHeader('Authorization'));
        $this->assertSame([], $this->sleeper->delays);
    }

    public function allMethods(): array
    {
        return [['GET'], ['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    public function testSecond401IsInvalidCredentialsFault()
    {
        $body = '{"type":"https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.2","title":"Unauthorized","status":401,'
            .'"o:errorDetails":[{"detail":"Invalid login attempt.","o:errorCode":"INVALID_LOGIN"}]}';
        $this->queue(new Response(401, [], $body), new Response(401, [], $body));

        $fault = $this->catchFault(function () {
            $this->client()->send('GET', '/record/v1/customer/42');
        });

        $this->assertCount(2, $this->transport->requests);
        $this->assertSame(1, $this->auth->invalidated);
        $this->assertSame(RestFault::INVALID_CREDENTIALS, $fault->_name);
        $this->assertSame(401, $fault->getHttpStatus());
        $this->assertStringContainsString('Invalid login attempt.', $fault->getMessage());
    }

    public function testBusinessErrorsAreReturned()
    {
        $this->queue(new Response(404, [], '{"title":"Record not found"}'));

        $response = $this->client()->send('GET', '/record/v1/customer/999');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(1, $this->transport->requests);
        $this->assertSame(0, $this->auth->invalidated);
    }

    public function testNoLogsWhenLoggingIsOff()
    {
        $this->queue(new Response(200));

        $this->client()->send('GET', '/record/v1/customer/42');

        $this->assertSame([], $this->logger->records);
    }

    public function testOneLogEntryPerExchangeWithSecretsRedacted()
    {
        $this->queue(
            new Response(503, ['Set-Cookie' => 'JSESSIONID=abc'], 'Service Unavailable'),
            new Response(200, ['Content-Type' => 'application/json'], '{"id":"42","access_token":"tok-secret","nested":{"password":"p@ss"}}')
        );

        $this->client(['logging' => true])->send('PUT', '/record/v1/customer/eid:A1', [], ['companyName' => 'Acme', 'ccNumber' => '4111']);

        $this->assertCount(2, $this->logger->records);
        $first = $this->logger->records[0];
        $this->assertSame('info', $first['level']);
        $this->assertSame(['operation' => 'rest', 'method' => 'PUT', 'url' => self::BASE_URL.'/record/v1/customer/eid:A1', 'status' => 503], $first['context']);
        $this->assertStringStartsWith('PUT '.self::BASE_URL.'/record/v1/customer/eid:A1', $first['message']);
        $this->assertStringContainsString('Authorization: [redacted]', $first['message']);
        $this->assertStringContainsString('Set-Cookie: [redacted]', $first['message']);
        $this->assertStringContainsString('{"companyName":"Acme","ccNumber":"[redacted]"}', $first['message']);
        $this->assertStringContainsString("HTTP 503\nSet-Cookie: [redacted]\n\nService Unavailable", $first['message']);

        $second = $this->logger->records[1]['message'];
        $this->assertStringContainsString('{"id":"42","access_token":"[redacted]","nested":{"password":"[redacted]"}}', $second);
        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('token-', $record['message']);
            $this->assertStringNotContainsString('tok-secret', $record['message']);
            $this->assertStringNotContainsString('4111', $record['message']);
            $this->assertStringNotContainsString('JSESSIONID', $record['message']);
        }
    }

    public function testUnredactedBodyIsLoggedVerbatim()
    {
        $this->queue(new Response(200, [], "{\"a\": \"x/y\"}"));

        $this->client(['logging' => '1'])->send('GET', '/record/v1/customer/42');

        $this->assertStringEndsWith("HTTP 200\n\n{\"a\": \"x/y\"}", $this->logger->records[0]['message']);
    }

    public function testTransportErrorIsLogged()
    {
        $this->transport->push(new TransportException('cURL error 7: refused'));
        $this->queue(new Response(200));

        $client = $this->client();
        $client->setLogging(true);
        $client->send('GET', '/record/v1/customer/42');

        $this->assertCount(2, $this->logger->records);
        $this->assertStringEndsWith('No response: cURL error 7: refused', $this->logger->records[0]['message']);
        $this->assertNull($this->logger->records[0]['context']['status']);
    }

    public function testSetLoggingOff()
    {
        $this->queue(new Response(200));

        $client = $this->client(['logging' => true]);
        $client->setLogging(false);
        $client->send('GET', '/record/v1/customer/42');

        $this->assertSame([], $this->logger->records);
    }

    public function testRecorderKeepsTheLastSignedExchange()
    {
        $this->queue(new Response(500), new Response(200, ['Content-Type' => 'application/json'], '{"id":"42"}'));

        $client = $this->client();
        $client->send('GET', '/record/v1/customer/42', ['expandSubResources' => true]);

        $recorder = $client->getRecorder();
        $this->assertSame($this->transport->requests[1], $recorder->getLastRequest());
        $this->assertSame(200, $recorder->getLastResponse()->getStatusCode());
        $this->assertSame(
            "GET ".self::BASE_URL."/record/v1/customer/42?expandSubResources=true HTTP/1.1\r\nAccept: application/json\r\nAuthorization: Bearer token-2",
            $recorder->getLastRequestHeaders()
        );
        $this->assertNull($recorder->getLastRequestBody());
        $this->assertSame("HTTP/1.1 200\r\nContent-Type: application/json", $recorder->getLastResponseHeaders());
        $this->assertSame('{"id":"42"}', $recorder->getLastResponseBody());
    }

    public function testRecorderAfterTransportFailureHasNoResponse()
    {
        $this->transport->push(new TransportException('cURL error 28: timeout'));

        $client = $this->client();
        $this->catchFault(function () use ($client) {
            $client->send('POST', '/record/v1/customer', [], ['x' => 1]);
        });

        $recorder = $client->getRecorder();
        $this->assertSame('{"x":1}', $recorder->getLastRequestBody());
        $this->assertNull($recorder->getLastResponse());
        $this->assertNull($recorder->getLastResponseHeaders());
        $this->assertNull($recorder->getLastResponseBody());
    }
}
