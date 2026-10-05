<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Record\RecordClient;

final class HandlerFactory
{
    /**
     * @param callable(): RestClient $restClient builds the shared client on first use
     * @return array<string, callable(): OperationHandlerInterface> operation => lazy handler
     */
    public static function create(callable $restClient): array
    {
        $get = null;
        $getHandler = function () use ($restClient, &$get) {
            if ($get === null) {
                $get = new GetHandler(new RecordClient(call_user_func($restClient)));
            }
            return $get;
        };

        return [
            'get'     => $getHandler,
            'getList' => function () use ($getHandler) {
                return new GetListHandler($getHandler());
            },
        ];
    }
}
