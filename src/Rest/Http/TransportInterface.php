<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

use NetSuite\Rest\Exception\TransportException;

interface TransportInterface
{
    /**
     * Sends the request once; any HTTP status is a response, not an error.
     *
     * @throws TransportException when no HTTP response was received
     */
    public function send(Request $request): Response;
}
