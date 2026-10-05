<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

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
