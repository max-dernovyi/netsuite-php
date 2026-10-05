<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

class SpyLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var array<int, array{level: string, message: string, context: array}> */
    public $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
