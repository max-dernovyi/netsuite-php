<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest;

use NetSuite\Rest\Dispatcher;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Http\CallRecorder;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\LastCall;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Handler\CallbackHandler;
use tests\Netsuite\Rest\Http\SpyLogger;

class DispatcherTest extends TestCase
{
    /** @var CallRecorder */
    private $recorder;
    /** @var LastCall */
    private $lastCall;
    /** @var SpyLogger */
    private $logger;
    /** @var array */
    private $fallbackCalls = [];

    protected function setUp(): void
    {
        $this->recorder = new CallRecorder();
        $this->lastCall = new LastCall($this->recorder, function () {
            return null;
        });
        $this->logger = new SpyLogger();
    }

    private function dispatcher(array $handlers, bool $withFallback = true): Dispatcher
    {
        $fallback = $withFallback
            ? function ($operation, $request) {
                $this->fallbackCalls[] = [$operation, $request];
                return 'soap:'.$operation;
            }
            : null;
        return new Dispatcher($handlers, $fallback, $this->lastCall, $this->logger);
    }

    public function testRoutesToTheHandler()
    {
        $handler = new CallbackHandler(function ($request) {
            return 'rest:'.$request->id;
        });
        $built = 0;
        $dispatcher = $this->dispatcher(['get' => function () use ($handler, &$built) {
            $built++;
            return $handler;
        }]);
        $request = (object) ['id' => 7];

        $this->assertTrue($dispatcher->handles('get'));
        $this->assertSame('rest:7', $dispatcher->dispatch('get', $request));
        $this->assertSame('rest:7', $dispatcher->dispatch('get', $request));
        $this->assertSame(1, $built);
        $this->assertSame([$request, $request], $handler->requests);
        $this->assertSame([], $this->fallbackCalls);
        $this->assertSame([], $this->logger->records);
    }

    public function testHandlerClearsThePreviousRecording()
    {
        $this->recorder->record(new Request('GET', 'https://example.test/old'), new Response(200));
        $dispatcher = $this->dispatcher(['get' => function () {
            return new CallbackHandler(function () {
                return null;
            });
        }]);

        $dispatcher->dispatch('get', (object) []);

        $this->assertNull($this->recorder->getLastRequest());
    }

    public function testHandlerLogsIgnoredSoapHeaders()
    {
        $dispatcher = $this->dispatcher(['get' => function () {
            return new CallbackHandler(function () {
                return null;
            });
        }]);

        $dispatcher->dispatch('get', (object) [], ['preferences', 'applicationInfo']);

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('debug', $this->logger->records[0]['level']);
        $this->assertSame(
            'NetSuite REST: operation "get" ignores SOAP headers: preferences, applicationInfo',
            $this->logger->records[0]['message']
        );
    }

    public function testFallsBackToSoapWithOneWarning()
    {
        $dispatcher = $this->dispatcher([]);
        $request = (object) [];

        $this->assertFalse($dispatcher->handles('search'));
        $this->assertSame('soap:search', $dispatcher->dispatch('search', $request, ['searchPreferences']));
        $this->assertSame([['search', $request]], $this->fallbackCalls);
        $this->assertSame([[
            'level'   => 'warning',
            'message' => 'NetSuite REST: operation "search" is not supported, sent via SOAP',
            'context' => ['operation' => 'search'],
        ]], $this->logger->records);
    }

    public function testThrowsWhenSoapIsUnavailable()
    {
        $dispatcher = $this->dispatcher([], false);

        try {
            $dispatcher->dispatch('search', (object) []);
            $this->fail('NotSupportedOnRestException expected');
        } catch (NotSupportedOnRestException $e) {
            $this->assertSame(
                'NetSuite REST: operation "search" is not supported (REST status: planned, SuiteQL) and cannot fall back to SOAP without TBA keys',
                $e->getMessage()
            );
        }
        $this->assertSame([], $this->logger->records);
    }

    public function testUnknownOperationWithoutSoap()
    {
        $this->expectException(NotSupportedOnRestException::class);
        $this->expectExceptionMessage('operation "login" is not supported (REST status: unknown operation)');

        $this->dispatcher([], false)->dispatch('login', (object) []);
    }
}
