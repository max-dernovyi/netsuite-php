<?php

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\TransportInterface;

/**
 * Returns queued responses (or throws queued exceptions) and records every request.
 */
class FakeTransport implements TransportInterface
{
    /** @var Request[] */
    public $requests = [];
    /** @var array<int, Response|\Throwable> */
    private $queue;

    /**
     * @param array<int, Response|\Throwable> $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    /**
     * @param Response|\Throwable $item
     */
    public function push($item): void
    {
        $this->queue[] = $item;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if (!$this->queue) {
            throw new \LogicException('FakeTransport: no response queued for '.$request->getMethod().' '.$request->getUrl());
        }
        $item = array_shift($this->queue);
        if ($item instanceof \Throwable) {
            throw $item;
        }
        return $item;
    }
}
