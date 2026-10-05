<?php

namespace tests\Netsuite\Parity;

use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Test as TestUtil;

class ParityEnvTest extends TestCase
{
    const ENV = [
        'NETSUITE_PARITY_ACCOUNT'  => '123456_SB1',
        'NETSUITE_CONSUMER_KEY'    => 'ck',
        'NETSUITE_CONSUMER_SECRET' => 'cs',
        'NETSUITE_TOKEN_KEY'       => 'tk',
        'NETSUITE_TOKEN_SECRET'    => 'ts',
    ];

    public function testFullEnvBuildsBothConfigs(): void
    {
        $env = ParityEnv::fromEnvironment($this->getenv(self::ENV + ['NETSUITE_PARITY_CUSTOMER_ID' => '647']));

        $this->assertNotNull($env);
        $this->assertSame([
            'account'            => '123456_SB1',
            'consumerKey'        => 'ck',
            'consumerSecret'     => 'cs',
            'token'              => 'tk',
            'tokenSecret'        => 'ts',
            'signatureAlgorithm' => 'sha256',
            'transport'          => 'soap',
            'endpoint'           => '2025_2',
            'host'               => 'https://123456-sb1.suitetalk.api.netsuite.com',
        ], $env->soapConfig());
        $this->assertSame('rest', $env->restConfig()['transport']);
        $this->assertSame('ck', $env->restConfig()['consumerKey']);
        $this->assertSame('647', $env->value('NETSUITE_PARITY_CUSTOMER_ID'));
        $this->assertNull($env->value('NETSUITE_PARITY_SALES_ORDER_ID'));
    }

    public function testHashTypeIsPassedThrough(): void
    {
        $env = ParityEnv::fromEnvironment($this->getenv(self::ENV + ['NETSUITE_HASH_TYPE' => 'sha1']));

        $this->assertSame('sha1', $env->soapConfig()['signatureAlgorithm']);
    }

    /**
     * @dataProvider requiredKeys
     */
    public function testMissingRequiredKeyDisablesParity(string $key): void
    {
        $env = self::ENV;
        $env[$key] = '';
        $this->assertNull(ParityEnv::fromEnvironment($this->getenv($env)));

        unset($env[$key]);
        $this->assertNull(ParityEnv::fromEnvironment($this->getenv($env)));
    }

    public function requiredKeys(): array
    {
        $keys = [];
        foreach (array_keys(self::ENV) as $key) {
            $keys[$key] = [$key];
        }
        return $keys;
    }

    public function testParityTestsAreGroupedAndSkippedWithoutEnv(): void
    {
        $saved = getenv('NETSUITE_PARITY_ACCOUNT');
        putenv('NETSUITE_PARITY_ACCOUNT');
        try {
            foreach (get_class_methods(ParityTest::class) as $method) {
                if (strpos($method, 'test') !== 0) {
                    continue;
                }
                $this->assertContains('parity', TestUtil::getGroups(ParityTest::class, $method));

                $result = (new ParityTest($method))->run();
                $this->assertSame(1, $result->skippedCount(), $method.' must be skipped');
            }
        } finally {
            if ($saved !== false) {
                putenv('NETSUITE_PARITY_ACCOUNT='.$saved);
            }
        }
    }

    private function getenv(array $env): callable
    {
        return function ($name) use ($env) {
            return array_key_exists($name, $env) ? $env[$name] : false;
        };
    }
}
