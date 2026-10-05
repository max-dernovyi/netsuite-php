<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Auth;

use NetSuite\Classes\ExceededConcurrentRequestLimitFault;
use NetSuite\Classes\FaultCodeType;
use NetSuite\Classes\InvalidCredentialsFault;
use NetSuite\Classes\UnexpectedErrorFault;
use NetSuite\Rest\Auth\OAuth2TokenClient;
use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Http\FakeTransport;

class OAuth2TokenClientTest extends TestCase
{
    const TOKEN_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token';
    const NOW = 1700000000;

    private function client(FakeTransport $transport): OAuth2TokenClient
    {
        return new OAuth2TokenClient($transport, self::TOKEN_URL, function () {
            return self::NOW;
        });
    }

    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testRequestsTokenWithClientAssertion()
    {
        $transport = new FakeTransport([self::json(200, [
            'access_token' => 'eyJ.access.token',
            'expires_in'   => '3600',
            'token_type'   => 'Bearer',
        ])]);

        $token = $this->client($transport)->requestToken('header.claims.sig');

        $this->assertSame('eyJ.access.token', $token->getValue());
        $this->assertSame(self::NOW + 3600, $token->getExpiresAt());

        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame(self::TOKEN_URL, $request->getUrl());
        $this->assertSame('application/x-www-form-urlencoded', $request->getHeader('content-type'));
        $this->assertSame('application/json', $request->getHeader('accept'));
        $this->assertNull($request->getHeader('Authorization'));
        parse_str($request->getBody(), $form);
        $this->assertSame([
            'grant_type'            => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion'      => 'header.claims.sig',
        ], $form);
    }

    public function testIntegerExpiresIn()
    {
        $transport = new FakeTransport([self::json(200, ['access_token' => 't', 'expires_in' => 1800])]);

        $this->assertSame(self::NOW + 1800, $this->client($transport)->requestToken('a')->getExpiresAt());
    }

    public function testTokenUrlFromConfig()
    {
        $config = RestConfig::fromArray([
            'account'             => '123456-sb1',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => 'pem',
        ]);
        $this->assertSame(self::TOKEN_URL, OAuth2TokenClient::fromConfig($config, new FakeTransport())->getTokenUrl());

        $config = RestConfig::fromArray([
            'account'          => '123456',
            'restBaseUrl'      => 'http://127.0.0.1:8080/services/rest/',
            'consumerKey'      => 'ck',
            'consumerSecret'   => 'cs',
            'token'            => 't',
            'tokenSecret'      => 'ts',
        ]);
        $this->assertSame(
            'http://127.0.0.1:8080/services/rest/auth/oauth2/v1/token',
            OAuth2TokenClient::fromConfig($config, new FakeTransport())->getTokenUrl()
        );
    }

    public function errorResponses(): array
    {
        return [
            'OAuth error with description' => [
                self::json(400, ['error' => 'invalid_grant', 'error_description' => 'The certificate has expired.']),
                'NetSuite OAuth 2.0 token request failed: invalid_grant: The certificate has expired.',
            ],
            'OAuth error without description' => [
                self::json(400, ['error' => 'invalid_request']),
                'NetSuite OAuth 2.0 token request failed: invalid_request',
            ],
            'REST error body' => [
                self::json(401, [
                    'type'            => 'https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.2',
                    'title'           => 'Unauthorized',
                    'status'          => 401,
                    'o:errorDetails'  => [[
                        'detail'      => 'Invalid login attempt. For more details, see the Login Audit Trail.',
                        'o:errorCode' => 'INVALID_LOGIN',
                    ]],
                ]),
                'NetSuite OAuth 2.0 token request failed: Invalid login attempt. For more details, see the Login Audit Trail.',
            ],
            'non-JSON body' => [
                new Response(403, [], 'Forbidden'),
                'NetSuite OAuth 2.0 token request failed: Forbidden',
            ],
        ];
    }

    /**
     * @dataProvider errorResponses
     */
    public function testErrorBecomesInvalidCredentialsFault(Response $response, string $message)
    {
        try {
            $this->client(new FakeTransport([$response]))->requestToken('a');
            $this->fail('RestFault expected');
        } catch (\SoapFault $e) {
            $this->assertInstanceOf(RestFault::class, $e);
            $this->assertInstanceOf(InvalidCredentialsFault::class, $e->getFault());
            $this->assertSame($message, $e->getMessage());
            $this->assertSame($message, $e->detail->invalidCredentialsFault->message);
            $this->assertSame(FaultCodeType::INVALID_LOGIN_CREDENTIALS, $e->detail->invalidCredentialsFault->code);
            $this->assertSame($response->getStatusCode(), $e->getHttpStatus());
        }
    }

    public function testThrottlingBecomesConcurrencyFault()
    {
        try {
            $this->client(new FakeTransport([self::json(429, ['error' => 'too_many_requests'])]))->requestToken('a');
            $this->fail('RestFault expected');
        } catch (RestFault $e) {
            $this->assertInstanceOf(ExceededConcurrentRequestLimitFault::class, $e->getFault());
        }
    }

    public function testServerErrorBecomesUnexpectedErrorFault()
    {
        try {
            $this->client(new FakeTransport([new Response(503, [], '')]))->requestToken('a');
            $this->fail('RestFault expected');
        } catch (RestFault $e) {
            $this->assertInstanceOf(UnexpectedErrorFault::class, $e->getFault());
            $this->assertSame(503, $e->getHttpStatus());
        }
    }

    public function malformedResponses(): array
    {
        return [
            'not JSON'             => [new Response(200, [], 'ok')],
            'no access_token'      => [self::json(200, ['expires_in' => 3600])],
            'empty access_token'   => [self::json(200, ['access_token' => '', 'expires_in' => 3600])],
            'no expires_in'        => [self::json(200, ['access_token' => 't'])],
            'zero expires_in'      => [self::json(200, ['access_token' => 't', 'expires_in' => 0])],
            'non-numeric expiry'   => [self::json(200, ['access_token' => 't', 'expires_in' => 'soon'])],
        ];
    }

    /**
     * @dataProvider malformedResponses
     */
    public function testMalformedSuccessResponse(Response $response)
    {
        $this->expectException(RestFault::class);
        $this->expectExceptionMessage('NetSuite OAuth 2.0 token response has no access_token or expires_in');

        $this->client(new FakeTransport([$response]))->requestToken('a');
    }

    public function testTransportErrorPropagates()
    {
        $this->expectException(TransportException::class);

        $this->client(new FakeTransport([new TransportException('Connection refused')]))->requestToken('a');
    }
}
