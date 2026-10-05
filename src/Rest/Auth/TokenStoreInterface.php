<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

/**
 * Keeps OAuth 2.0 access tokens; implement it to share tokens between processes.
 */
interface TokenStoreInterface
{
    public function get(string $key): ?AccessToken;

    public function set(string $key, AccessToken $token): void;

    public function delete(string $key): void;
}
