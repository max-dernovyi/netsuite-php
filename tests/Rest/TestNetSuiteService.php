<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

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
    /** @var array<string, callable(callable): object>|null operation => fn($restClient) returning a handler; null for the real ones */
    public $handlers = [];
    /** @var TransportInterface */
    public $transport;

    protected function createRestHandlers(callable $restClient): array
    {
        if ($this->handlers === null) {
            return parent::createRestHandlers($restClient);
        }
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
