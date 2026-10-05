<?php

namespace tests\Netsuite\Parity;

use NetSuite\Rest\Config\RestConfig;

/**
 * Parity settings from the environment; null unless the account and all TBA keys are set.
 */
class ParityEnv
{
    const REQUIRED = [
        'NETSUITE_PARITY_ACCOUNT'  => 'account',
        'NETSUITE_CONSUMER_KEY'    => 'consumerKey',
        'NETSUITE_CONSUMER_SECRET' => 'consumerSecret',
        'NETSUITE_TOKEN_KEY'       => 'token',
        'NETSUITE_TOKEN_SECRET'    => 'tokenSecret',
    ];

    /** @var array */
    private $config;

    /** @var callable */
    private $getenv;

    private function __construct(array $config, callable $getenv)
    {
        $this->config = $config;
        $this->getenv = $getenv;
    }

    /**
     * @param callable|null $getenv fn(string $name): string|false, defaults to getenv()
     */
    public static function fromEnvironment(?callable $getenv = null): ?self
    {
        $getenv = $getenv ?? function ($name) {
            return getenv($name);
        };

        $config = [];
        foreach (self::REQUIRED as $env => $key) {
            $value = $getenv($env);
            if ($value === false || $value === '') {
                return null;
            }
            $config[$key] = $value;
        }
        $config['signatureAlgorithm'] = $getenv('NETSUITE_HASH_TYPE') ?: RestConfig::DEFAULT_SIGNATURE_ALGORITHM;

        return new self($config, $getenv);
    }

    public function soapConfig(): array
    {
        list(, $host) = RestConfig::parseAccount($this->config['account']);

        return $this->config + [
            'transport' => RestConfig::TRANSPORT_SOAP,
            'endpoint'  => '2025_2',
            'host'      => 'https://'.$host.'.suitetalk.api.netsuite.com',
        ];
    }

    public function restConfig(): array
    {
        return ['transport' => RestConfig::TRANSPORT_REST] + $this->config;
    }

    /**
     * An optional scenario value such as NETSUITE_PARITY_CUSTOMER_ID.
     */
    public function value(string $name): ?string
    {
        $value = ($this->getenv)($name);
        return $value === false || $value === '' ? null : (string) $value;
    }
}
