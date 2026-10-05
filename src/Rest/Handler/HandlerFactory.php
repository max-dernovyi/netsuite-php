<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Rest\Http\RestClient;

final class HandlerFactory
{
    /**
     * @param callable(): RestClient $restClient builds the shared client on first use
     * @return array<string, callable(): OperationHandlerInterface> operation => lazy handler
     */
    public static function create(callable $restClient): array
    {
        return [];
    }
}
