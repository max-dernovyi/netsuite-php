<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\WriteResponse;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;

/**
 * Writes one record or reference; API errors come back as a failed status.
 */
interface RecordWriterInterface
{
    /**
     * @param mixed $item a record, or a reference for delete
     * @throws RestFault|NotSupportedOnRestException
     */
    public function write($item): WriteResponse;
}
