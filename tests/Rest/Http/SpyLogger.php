<?php

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
