<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

use NetSuite\Rest\Http\Request;

interface AuthenticatorInterface
{
    /**
     * Returns a copy of the request with a fresh Authorization header.
     */
    public function authorize(Request $request): Request;

    /**
     * Drops cached credentials so the next authorize() obtains new ones.
     */
    public function invalidate(): void;
}
