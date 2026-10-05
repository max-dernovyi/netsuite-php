<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Auth;

use NetSuite\Classes\InvalidCredentialsFault;
use NetSuite\Rest\Auth\AccessToken;
use NetSuite\Rest\Auth\AuthenticatorInterface;
use NetSuite\Rest\Auth\InMemoryTokenStore;
use NetSuite\Rest\Auth\JwtAssertionBuilder;
use NetSuite\Rest\Auth\OAuth2Authenticator;
use NetSuite\Rest\Auth\OAuth2TokenClient;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Http\FakeTransport;

class OAuth2AuthenticatorTest extends TestCase
{
    const BASE_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest';
    const RECORD_URL = self::BASE_URL.'/record/v1/customer/42';

    /** @var string */
    private static $pem;
    /** @var int */
    private $now = 1700000000;
    /** @var FakeTransport */
    private $transport;
    /** @var InMemoryTokenStore */
    private $store;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, self::$pem);
    }

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->store = new InMemoryTokenStore();
    }

    private function config(): RestConfig
    {
        return RestConfig::fromArray([
            'transport'           => 'rest',
            'account'             => '123456_SB1',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => self::$pem,
            'oauth2Algorithm'     => 'ES256',
        ]);
    }

    private function authenticator(): OAuth2Authenticator
    {
        return OAuth2Authenticator::fromConfig($this->config(), $this->transport, $this->store, function () {
            return $this->now;
        });
    }

    private function queueToken(string $token, int $expiresIn = 3600): void
    {
        $this->transport->push(new Response(200, [], json_encode(['access_token' => $token, 'expires_in' => $expiresIn])));
    }

    private function authorize(OAuth2Authenticator $authenticator): ?string
    {
        return $authenticator->authorize(new Request('GET', self::RECORD_URL))->getHeader('Authorization');
    }

    public function testImplementsInterface()
    {
        $this->assertInstanceOf(AuthenticatorInterface::class, $this->authenticator());
    }

    public function testStoreMissFetchesTokenAndSetsBearerHeader()
    {
        $this->queueToken('token-1');

        $this->assertSame('Bearer token-1', $this->authorize($this->authenticator()));

        $this->assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        $this->assertSame(self::BASE_URL.'/auth/oauth2/v1/token', $request->getUrl());
        parse_str($request->getBody(), $form);
        $claims = json_decode(base64_decode(strtr(explode('.', $form['client_assertion'])[1], '-_', '+/')), true);
        $this->assertSame(self::BASE_URL.'/auth/oauth2/v1/token', $claims['aud']);
        $this->assertSame($this->now, $claims['iat']);

        $stored = $this->store->get(OAuth2Authenticator::storeKey($this->config()));
        $this->assertSame('token-1', $stored->getValue());
        $this->assertSame($this->now + 3600, $stored->getExpiresAt());
    }

    public function testStoreHitReusesToken()
    {
        $this->queueToken('token-1');
        $authenticator = $this->authenticator();

        $this->authorize($authenticator);
        $this->now += 3600 - 61;
        $this->assertSame('Bearer token-1', $this->authorize($authenticator));

        $this->assertCount(1, $this->transport->requests);
    }

    public function testTokenIsRefreshedSixtySecondsBeforeExpiry()
    {
        $this->queueToken('token-1');
        $this->queueToken('token-2');
        $authenticator = $this->authenticator();

        $this->authorize($authenticator);
        $this->now += 3600 - 60;

        $this->assertSame('Bearer token-2', $this->authorize($authenticator));
        $this->assertCount(2, $this->transport->requests);
    }

    public function testTokenFromSharedStoreIsUsed()
    {
        $this->store->set(OAuth2Authenticator::storeKey($this->config()), new AccessToken('shared', $this->now + 600));

        $this->assertSame('Bearer shared', $this->authorize($this->authenticator()));
        $this->assertCount(0, $this->transport->requests);
    }

    public function testInvalidateDropsToken()
    {
        $this->queueToken('token-1');
        $this->queueToken('token-2');
        $authenticator = $this->authenticator();

        $this->authorize($authenticator);
        $authenticator->invalidate();

        $this->assertNull($this->store->get(OAuth2Authenticator::storeKey($this->config())));
        $this->assertSame('Bearer token-2', $this->authorize($authenticator));
    }

    public function testReplacesExistingAuthorizationHeader()
    {
        $this->queueToken('token-1');
        $request = new Request('GET', self::RECORD_URL, ['authorization' => 'Bearer stale']);

        $authorized = $this->authenticator()->authorize($request);

        $this->assertSame(['Authorization' => 'Bearer token-1'], $authorized->getHeaders());
        $this->assertSame('Bearer stale', $request->getHeader('Authorization'));
    }

    public function testTokenErrorPropagatesAsFaultAndNothingIsStored()
    {
        $this->transport->push(new Response(400, [], json_encode(['error' => 'invalid_client'])));

        try {
            $this->authorize($this->authenticator());
            $this->fail('RestFault expected');
        } catch (RestFault $e) {
            $this->assertInstanceOf(InvalidCredentialsFault::class, $e->getFault());
            $this->assertStringContainsString('invalid_client', $e->getMessage());
        }
        $this->assertNull($this->store->get(OAuth2Authenticator::storeKey($this->config())));
    }

    public function testDefaultStoreAndKey()
    {
        $this->queueToken('token-1');
        $authenticator = new OAuth2Authenticator(
            JwtAssertionBuilder::fromConfig($this->config()),
            OAuth2TokenClient::fromConfig($this->config(), $this->transport)
        );

        $this->assertSame('Bearer token-1', $this->authorize($authenticator));
        $this->assertSame('Bearer token-1', $this->authorize($authenticator));
        $this->assertCount(1, $this->transport->requests);
    }

    public function testStoreKeyDependsOnAccountAndIntegration()
    {
        $base = [
            'account'             => '123456_SB1',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => 'pem',
        ];
        $key = OAuth2Authenticator::storeKey(RestConfig::fromArray($base));

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', $key);
        $this->assertSame($key, OAuth2Authenticator::storeKey(RestConfig::fromArray(['account' => '123456-sb1'] + $base)));
        $this->assertNotSame($key, OAuth2Authenticator::storeKey(RestConfig::fromArray(['account' => '123456'] + $base)));
        $this->assertNotSame($key, OAuth2Authenticator::storeKey(RestConfig::fromArray(['oauth2ClientId' => 'other'] + $base)));
        $this->assertNotSame($key, OAuth2Authenticator::storeKey(RestConfig::fromArray(['oauth2CertificateId' => 'other'] + $base)));
    }

    public function testUnsupportedAlgorithmFromConfig()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('oauth2Algorithm "RS256" is not supported');

        OAuth2Authenticator::fromConfig(
            RestConfig::fromArray(['oauth2Algorithm' => 'RS256'] + [
                'account'             => '123456',
                'oauth2ClientId'      => 'client-id',
                'oauth2CertificateId' => 'cert-id',
                'oauth2PrivateKey'    => self::$pem,
            ]),
            $this->transport
        );
    }
}
