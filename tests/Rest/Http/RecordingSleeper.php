<?php

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Http\SleeperInterface;

class RecordingSleeper implements SleeperInterface
{
    /** @var float[] */
    public $delays = [];

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
    }
}
