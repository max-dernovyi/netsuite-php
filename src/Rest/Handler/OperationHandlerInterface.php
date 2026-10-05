<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

/**
 * Runs one NetSuiteService operation over REST.
 */
interface OperationHandlerInterface
{
    /**
     * @param object $request the generated `Classes\*Request`
     * @return object the generated `Classes\*Response`
     */
    public function handle($request);
}
