<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Config;

use NetSuite\Rest\Config\RestConfig;
use PHPUnit\Framework\TestCase;

class RestConfigTest extends TestCase
{
    private function tbaConfig(array $overrides = []): array
    {
        return array_merge([
            'transport'      => 'rest',
            'account'        => '123456',
            'consumerKey'    => 'consumer-key',
            'consumerSecret' => 'consumer-secret',
            'token'          => 'token-id',
            'tokenSecret'    => 'token-secret',
        ], $overrides);
    }

    private function oauth2Config(array $overrides = []): array
    {
        return array_merge([
            'transport'           => 'rest',
            'account'             => '123456',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => '/path/to/key.pem',
        ], $overrides);
    }

    public function testTransportDefaultsToSoap()
    {
        $config = $this->tbaConfig();
        unset($config['transport']);

        $this->assertSame('soap', RestConfig::fromArray($config)->transport());
        $this->assertFalse(RestConfig::fromArray($config)->isRest());
        $this->assertSame('soap', RestConfig::transportOf(['transport' => '']));
    }

    /**
     * @dataProvider transportProvider
     */
    public function testTransportValues($value, string $expected)
    {
        $this->assertSame($expected, RestConfig::fromArray($this->tbaConfig(['transport' => $value]))->transport());
    }

    public static function transportProvider(): array
    {
        return [
            'soap'        => ['soap', 'soap'],
            'rest'        => ['rest', 'rest'],
            'upper case'  => ['REST', 'rest'],
            'whitespace'  => [' rest ', 'rest'],
        ];
    }

    /**
     * @dataProvider invalidTransportProvider
     */
    public function testInvalidTransportThrows($value)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('transport');

        RestConfig::fromArray($this->tbaConfig(['transport' => $value]));
    }

    public static function invalidTransportProvider(): array
    {
        return [
            'unknown' => ['graphql'],
            'array'   => [['rest']],
            'bool'    => [true],
        ];
    }

    /**
     * @dataProvider accountProvider
     */
    public function testRealmAndHost(string $account, string $realm, string $host)
    {
        $config = RestConfig::fromArray($this->tbaConfig(['account' => $account]));

        $this->assertSame($realm, $config->realm());
        $this->assertSame($host, $config->host());
        $this->assertSame('https://'.$host.'.suitetalk.api.netsuite.com/services/rest', $config->baseUrl());
    }

    public static function accountProvider(): array
    {
        return [
            'production'         => ['123456', '123456', '123456'],
            'sandbox'            => ['123456_SB1', '123456_SB1', '123456-sb1'],
            'sandbox lower case' => ['123456_sb1', '123456_SB1', '123456-sb1'],
            'sandbox hyphen'     => ['123456-sb1', '123456_SB1', '123456-sb1'],
            'sandbox mixed'      => ['123456-Sb2', '123456_SB2', '123456-sb2'],
            'release preview'    => ['123456_RP', '123456_RP', '123456-rp'],
            'alphanumeric'       => ['tstdrv1234567', 'TSTDRV1234567', 'tstdrv1234567'],
            'surrounding spaces' => [' 123456_SB1 ', '123456_SB1', '123456-sb1'],
        ];
    }

    /**
     * @dataProvider invalidAccountProvider
     */
    public function testInvalidAccountThrows(string $account)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key invalid: account');

        RestConfig::fromArray($this->tbaConfig(['account' => $account]));
    }

    public static function invalidAccountProvider(): array
    {
        return [
            'space inside'      => ['123 456'],
            'dot'               => ['123456.sb1'],
            'slash'             => ['123456/sb1'],
            'leading separator' => ['_123456'],
            'trailing hyphen'   => ['123456-'],
            'double separator'  => ['123456__SB1'],
            'host injection'    => ['evil.com#'],
        ];
    }

    /**
     * @dataProvider missingAccountProvider
     */
    public function testMissingAccountThrows(array $overrides, bool $unset)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: account');

        $config = $this->tbaConfig($overrides);
        if ($unset) {
            unset($config['account']);
        }
        RestConfig::fromArray($config);
    }

    public static function missingAccountProvider(): array
    {
        return [
            'absent' => [[], true],
            'empty'  => [['account' => ''], false],
        ];
    }

    public function testRestBaseUrlOverride()
    {
        $config = RestConfig::fromArray($this->tbaConfig(['restBaseUrl' => 'http://127.0.0.1:8080/services/rest/']));

        $this->assertSame('http://127.0.0.1:8080/services/rest', $config->baseUrl());
        $this->assertSame('123456', $config->realm());
    }

    public function testTbaAuthDetected()
    {
        $config = RestConfig::fromArray($this->tbaConfig());

        $this->assertSame(RestConfig::AUTH_TBA, $config->authType());
        $this->assertTrue($config->hasTbaKeys());
        $this->assertSame('consumer-key', $config->consumerKey());
        $this->assertSame('consumer-secret', $config->consumerSecret());
        $this->assertSame('token-id', $config->token());
        $this->assertSame('token-secret', $config->tokenSecret());
        $this->assertSame('sha256', $config->signatureAlgorithm());
        $this->assertNull($config->oauth2ClientId());
    }

    public function testSignatureAlgorithmIsKept()
    {
        $config = RestConfig::fromArray($this->tbaConfig(['signatureAlgorithm' => 'sha1']));

        $this->assertSame('sha1', $config->signatureAlgorithm());
    }

    public function testOAuth2AuthDetected()
    {
        $config = RestConfig::fromArray($this->oauth2Config());

        $this->assertSame(RestConfig::AUTH_OAUTH2, $config->authType());
        $this->assertFalse($config->hasTbaKeys());
        $this->assertSame('client-id', $config->oauth2ClientId());
        $this->assertSame('cert-id', $config->oauth2CertificateId());
        $this->assertSame('/path/to/key.pem', $config->oauth2PrivateKey());
        $this->assertSame('PS256', $config->oauth2Algorithm());
        $this->assertNull($config->consumerKey());
    }

    public function testOAuth2AlgorithmIsUpperCased()
    {
        $config = RestConfig::fromArray($this->oauth2Config(['oauth2Algorithm' => 'es256']));

        $this->assertSame('ES256', $config->oauth2Algorithm());
    }

    public function testOAuth2WinsWhenBothSetsAreComplete()
    {
        $config = RestConfig::fromArray(array_merge($this->tbaConfig(), $this->oauth2Config()));

        $this->assertSame(RestConfig::AUTH_OAUTH2, $config->authType());
        $this->assertTrue($config->hasTbaKeys());
    }

    public function testPartialOAuth2FallsBackToCompleteTba()
    {
        $config = RestConfig::fromArray($this->tbaConfig(['oauth2ClientId' => 'client-id']));

        $this->assertSame(RestConfig::AUTH_TBA, $config->authType());
    }

    /**
     * @dataProvider missingTbaKeyProvider
     */
    public function testMissingTbaKeyThrows(string $key)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: '.$key);

        $config = $this->tbaConfig();
        unset($config[$key]);
        RestConfig::fromArray($config);
    }

    public static function missingTbaKeyProvider(): array
    {
        return [
            'consumerKey'    => ['consumerKey'],
            'consumerSecret' => ['consumerSecret'],
            'token'          => ['token'],
            'tokenSecret'    => ['tokenSecret'],
        ];
    }

    /**
     * @dataProvider missingOAuth2KeyProvider
     */
    public function testMissingOAuth2KeyThrows(string $key)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: '.$key);

        $config = $this->oauth2Config();
        $config[$key] = '';
        RestConfig::fromArray($config);
    }

    public static function missingOAuth2KeyProvider(): array
    {
        return [
            'oauth2ClientId'      => ['oauth2ClientId'],
            'oauth2CertificateId' => ['oauth2CertificateId'],
            'oauth2PrivateKey'    => ['oauth2PrivateKey'],
        ];
    }

    public function testNoAuthKeysReportsFirstTbaKey()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: consumerKey');

        RestConfig::fromArray(['transport' => 'rest', 'account' => '123456']);
    }

    public function testTimeoutAndMaxAttemptsDefaults()
    {
        $config = RestConfig::fromArray($this->tbaConfig());

        $this->assertSame(RestConfig::DEFAULT_TIMEOUT, $config->timeout());
        $this->assertSame(RestConfig::DEFAULT_MAX_ATTEMPTS, $config->maxAttempts());
    }

    public function testTimeoutAndMaxAttemptsOverrides()
    {
        $config = RestConfig::fromArray($this->tbaConfig(['timeout' => '2.5', 'maxAttempts' => '5']));

        $this->assertSame(2.5, $config->timeout());
        $this->assertSame(5, $config->maxAttempts());
    }

    public function testLogging()
    {
        $this->assertFalse(RestConfig::fromArray($this->tbaConfig())->logging());
        $this->assertFalse(RestConfig::fromArray($this->tbaConfig(['logging' => false]))->logging());
        $this->assertTrue(RestConfig::fromArray($this->tbaConfig(['logging' => true]))->logging());
    }

    /**
     * @dataProvider invalidLimitProvider
     */
    public function testInvalidTimeoutAndMaxAttemptsThrow(string $key, $value)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key invalid: '.$key);

        RestConfig::fromArray($this->tbaConfig([$key => $value]));
    }

    public static function invalidLimitProvider(): array
    {
        return [
            'zero timeout'         => ['timeout', 0],
            'negative timeout'     => ['timeout', -1],
            'text timeout'         => ['timeout', 'soon'],
            'zero maxAttempts'     => ['maxAttempts', 0],
            'fraction maxAttempts' => ['maxAttempts', 1.5],
            'text maxAttempts'     => ['maxAttempts', 'many'],
        ];
    }
}
