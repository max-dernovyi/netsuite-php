<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

final class NativeSleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds > 0) {
            usleep((int) round($seconds * 1000000));
        }
    }
}
