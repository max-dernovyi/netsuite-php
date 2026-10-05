<?php

namespace tests\Netsuite\Rest;

use NetSuite\NetSuiteService;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Http\TransportInterface;
use tests\Netsuite\Rest\Http\FakeTransport;

/**
 * NetSuiteService with injectable REST handlers and transport.
 */
class TestNetSuiteService extends NetSuiteService
{
    /** @var array<string, callable(callable): object> operation => fn($restClient) returning a handler */
    public $handlers = [];
    /** @var TransportInterface */
    public $transport;

    protected function createRestHandlers(callable $restClient): array
    {
        $factories = [];
        foreach ($this->handlers as $operation => $make) {
            $factories[$operation] = function () use ($make, $restClient) {
                return $make($restClient);
            };
        }
        return $factories;
    }

    protected function createRestTransport(RestConfig $config): TransportInterface
    {
        return $this->transport ?: ($this->transport = new FakeTransport());
    }
}
