<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Auth\AuthenticatorInterface;
use NetSuite\Rest\Http\Request;

/**
 * Signs each request with a numbered Bearer token so every attempt is distinguishable.
 */
class CountingAuthenticator implements AuthenticatorInterface
{
    /** @var int */
    public $authorized = 0;
    /** @var int */
    public $invalidated = 0;
    /** @var \Throwable[] thrown by the next authorize() calls */
    public $failures = [];

    public function authorize(Request $request): Request
    {
        if ($this->failures) {
            throw array_shift($this->failures);
        }
        $this->authorized++;
        return $request->withHeader('Authorization', 'Bearer token-'.$this->authorized);
    }

    public function invalidate(): void
    {
        $this->invalidated++;
    }
}
