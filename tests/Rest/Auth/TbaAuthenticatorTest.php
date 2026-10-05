<?php

namespace tests\Netsuite\Rest\Auth;

use NetSuite\Rest\Auth\AuthenticatorInterface;
use NetSuite\Rest\Auth\TbaAuthenticator;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Http\Request;
use PHPUnit\Framework\TestCase;

class TbaAuthenticatorTest extends TestCase
{
    const RECORD_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/record/v1/customer';

    private function authenticator(array $overrides = []): TbaAuthenticator
    {
        $args = array_merge([
            'realm'          => '123456_SB1',
            'consumerKey'    => 'consumer-key',
            'consumerSecret' => 'consumer-secret',
            'token'          => 'token-id',
            'tokenSecret'    => 'token-secret',
            'algorithm'      => 'sha256',
        ], $overrides);

        return new TbaAuthenticator(
            $args['realm'],
            $args['consumerKey'],
            $args['consumerSecret'],
            $args['token'],
            $args['tokenSecret'],
            $args['algorithm'],
            function () {
                return 'abc123nonce';
            },
            function () {
                return 1700000000;
            }
        );
    }

    private static function params(string $header): array
    {
        preg_match_all('/(\w+)="([^"]*)"/', $header, $m);
        return array_combine($m[1], $m[2]);
    }

    public function testImplementsInterface()
    {
        $this->assertInstanceOf(AuthenticatorInterface::class, $this->authenticator());
    }

    public function testHeaderMatchesPrecomputedVector()
    {
        // Reference signature computed independently with Python's hmac module.
        $request = new Request('GET', self::RECORD_URL.'/42?expandSubResources=true');

        $header = $this->authenticator()->authorize($request)->getHeader('Authorization');

        $this->assertSame(
            'OAuth realm="123456_SB1", oauth_consumer_key="consumer-key", oauth_nonce="abc123nonce", '
            .'oauth_signature_method="HMAC-SHA256", oauth_timestamp="1700000000", oauth_token="token-id", '
            .'oauth_version="1.0", oauth_signature="NeqU7wZTIjvhDvm%2FaNr1l5mbbb2r6dVNZ1%2FfybXrAkM%3D"',
            $header
        );
    }

    public function testSecretsAreEncodedInTheKey()
    {
        $request = new Request('POST', self::RECORD_URL, ['Content-Type' => 'application/json'], '{"companyName":"A"}');

        $header = $this->authenticator(['consumerSecret' => 'consumer secret&1', 'tokenSecret' => 'token/secret'])
            ->authorize($request)
            ->getHeader('Authorization');

        $this->assertSame('U77VbTznN3vTBIs1dIXWNF9ovBLjrZljIDFPr8KQ5yA%3D', self::params($header)['oauth_signature']);
    }

    public function testQueryParametersChangeTheSignature()
    {
        $auth = $this->authenticator();
        $plain = self::params($auth->authorize(new Request('GET', self::RECORD_URL.'/42'))->getHeader('Authorization'));
        $query = self::params(
            $auth->authorize(new Request('GET', self::RECORD_URL.'/42?expandSubResources=true'))->getHeader('Authorization')
        );

        $this->assertNotSame($plain['oauth_signature'], $query['oauth_signature']);
    }

    public function testQueryEncodingAndOrderDoNotChangeTheSignature()
    {
        $auth = $this->authenticator();
        $expected = 'r06YfAjAWuC5eqyHfbr13oTKVXWHDhZLHZmn6D6gbUE%3D';

        foreach ([
            '/42?q=name%20IS%20%27A%20B%27&limit=5',
            '/42?limit=5&q=name+IS+%27A+B%27',
        ] as $suffix) {
            $header = $auth->authorize(new Request('GET', self::RECORD_URL.$suffix))->getHeader('Authorization');
            $this->assertSame($expected, self::params($header)['oauth_signature'], $suffix);
        }
    }

    public function testDefaultPortAndHostCaseAreNormalised()
    {
        $auth = $this->authenticator();
        $expected = self::params($auth->authorize(new Request('GET', self::RECORD_URL))->getHeader('Authorization'));
        $variant = self::params($auth->authorize(new Request(
            'GET',
            'HTTPS://123456-SB1.suitetalk.api.netsuite.com:443/services/rest/record/v1/customer'
        ))->getHeader('Authorization'));

        $this->assertSame($expected['oauth_signature'], $variant['oauth_signature']);
    }

    public function testRealmIsNotEncoded()
    {
        $header = $this->authenticator(['realm' => '123456_SB1'])
            ->authorize(new Request('GET', self::RECORD_URL))
            ->getHeader('Authorization');

        $this->assertStringStartsWith('OAuth realm="123456_SB1", ', $header);
    }

    public function testDefaultProvidersGiveFreshNonceAndCurrentTimestamp()
    {
        $auth = new TbaAuthenticator('123456', 'ck', 'cs', 'tk', 'ts');
        $request = new Request('GET', self::RECORD_URL);

        $first = self::params($auth->authorize($request)->getHeader('Authorization'));
        $second = self::params($auth->authorize($request)->getHeader('Authorization'));

        $this->assertNotSame($first['oauth_nonce'], $second['oauth_nonce']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first['oauth_nonce']);
        $this->assertLessThanOrEqual(5, abs(time() - (int) $first['oauth_timestamp']));
    }

    public function testAuthorizeReplacesExistingHeaderAndKeepsTheRequest()
    {
        $request = new Request('PATCH', self::RECORD_URL.'/42', ['authorization' => 'old', 'Accept' => 'application/json'], '{}');

        $signed = $this->authenticator()->authorize($request);

        $this->assertSame('old', $request->getHeader('Authorization'));
        $this->assertStringStartsWith('OAuth ', $signed->getHeader('Authorization'));
        $this->assertSame('application/json', $signed->getHeader('Accept'));
        $this->assertSame('PATCH', $signed->getMethod());
        $this->assertSame('{}', $signed->getBody());
    }

    public function testInvalidateIsANoOp()
    {
        $auth = $this->authenticator();
        $request = new Request('GET', self::RECORD_URL);
        $before = $auth->authorize($request)->getHeader('Authorization');

        $auth->invalidate();

        $this->assertSame($before, $auth->authorize($request)->getHeader('Authorization'));
    }

    public function testFromConfig()
    {
        $config = RestConfig::fromArray([
            'transport'      => 'rest',
            'account'        => '123456-sb1',
            'consumerKey'    => 'consumer-key',
            'consumerSecret' => 'consumer-secret',
            'token'          => 'token-id',
            'tokenSecret'    => 'token-secret',
        ]);
        $auth = TbaAuthenticator::fromConfig($config, function () {
            return 'abc123nonce';
        }, function () {
            return 1700000000;
        });

        $header = $auth->authorize(new Request('GET', self::RECORD_URL.'/42?expandSubResources=true'))
            ->getHeader('Authorization');

        $this->assertSame('123456_SB1', self::params($header)['realm']);
        $this->assertSame('NeqU7wZTIjvhDvm%2FaNr1l5mbbb2r6dVNZ1%2FfybXrAkM%3D', self::params($header)['oauth_signature']);
    }

    /**
     * @dataProvider missingKeyProvider
     */
    public function testMissingKeyThrows(string $key)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: '.$key);

        $this->authenticator([$key === 'account' ? 'realm' : $key => '']);
    }

    public static function missingKeyProvider(): array
    {
        return [
            'account'        => ['account'],
            'consumerKey'    => ['consumerKey'],
            'consumerSecret' => ['consumerSecret'],
            'token'          => ['token'],
            'tokenSecret'    => ['tokenSecret'],
        ];
    }

    public function testFromConfigWithoutTbaKeysThrows()
    {
        $config = RestConfig::fromArray([
            'transport'           => 'rest',
            'account'             => '123456',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => '/path/to/key.pem',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key missing: consumerKey');

        TbaAuthenticator::fromConfig($config);
    }

    /**
     * @dataProvider unsupportedAlgorithmProvider
     */
    public function testUnsupportedAlgorithmThrows(string $algorithm)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signatureAlgorithm');

        $this->authenticator(['algorithm' => $algorithm]);
    }

    public static function unsupportedAlgorithmProvider(): array
    {
        return [
            'sha1'   => ['sha1'],
            'sha512' => ['sha512'],
            'md5'    => ['md5'],
        ];
    }

    public function testAlgorithmIsCaseInsensitive()
    {
        $this->assertInstanceOf(TbaAuthenticator::class, $this->authenticator(['algorithm' => 'SHA256']));
    }

    public function testRelativeUrlThrows()
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->authenticator()->authorize(new Request('GET', '/record/v1/customer'));
    }
}
