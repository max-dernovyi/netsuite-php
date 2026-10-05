<?php

namespace tests\Netsuite\Rest\Handler;

use NetSuite\Rest\Handler\OperationHandlerInterface;

class CallbackHandler implements OperationHandlerInterface
{
    /** @var callable */
    private $callback;
    /** @var array */
    public $requests = [];

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function handle($request)
    {
        $this->requests[] = $request;
        return call_user_func($this->callback, $request);
    }
}
