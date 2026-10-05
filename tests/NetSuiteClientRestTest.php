<?php

namespace tests\Netsuite;

use NetSuite\Classes\GetRequest;
use NetSuite\Classes\GetResponse;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchResponse;
use NetSuite\NetSuiteClient;
use NetSuite\NetSuiteService;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Handler\HandlerFactory;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\LastCall;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use tests\Netsuite\Rest\Handler\CallbackHandler;
use tests\Netsuite\Rest\Http\FakeTransport;
use tests\Netsuite\Rest\Http\SpyLogger;
use tests\Netsuite\Rest\OperationCatalogTest;
use tests\Netsuite\Rest\TestNetSuiteService;

class NetSuiteClientRestTest extends TestCase
{
    /** @var SpyLogger */
    private $logger;

    protected function setUp(): void
    {
        $this->logger = new SpyLogger();
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'transport'      => 'rest',
            'account'        => '123456_SB1',
            'consumerKey'    => 'consumer-key',
            'consumerSecret' => 'consumer-secret',
            'token'          => 'token-id',
            'tokenSecret'    => 'token-secret',
            'restBaseUrl'    => 'https://rest.example.test/services/rest',
        ], $overrides);
    }

    private function service(array $config, ?\SoapClient $soap = null, array $handlers = []): TestNetSuiteService
    {
        $service = new TestNetSuiteService($config, [], $soap, $this->logger);
        $service->handlers = $handlers;
        return $service;
    }

    /**
     * @return \SoapClient&MockObject
     */
    private function soap(): \SoapClient
    {
        return $this->createMock(\SoapClient::class);
    }

    /**
     * @return mixed a private NetSuiteClient property
     */
    private function privateOf(NetSuiteService $service, string $property)
    {
        return \Closure::bind(function () use ($property) {
            return $this->$property;
        }, $service, NetSuiteClient::class)();
    }

    public function testRestModeRoutesToTheHandler()
    {
        $soap = $this->soap();
        $soap->expects($this->never())->method('__soapCall');
        $response = new GetResponse();
        $handler = new CallbackHandler(function () use ($response) {
            return $response;
        });
        $service = $this->service($this->config(), $soap, ['get' => function () use ($handler) {
            return $handler;
        }]);
        $request = new GetRequest();

        $this->assertSame($response, $service->get($request));
        $this->assertSame([$request], $handler->requests);
        $this->assertSame([], $this->logger->records);
    }

    public function testFallbackCallsTheInjectedSoapClientAndWarnsOnce()
    {
        $request = new SearchRequest();
        $response = new SearchResponse();
        $soap = $this->soap();
        $soap->expects($this->once())
            ->method('__soapCall')
            ->with('search', [$request], null, $this->callback(function ($headers) {
                return isset($headers['tokenPassport'], $headers['preferences']);
            }))
            ->willReturn($response);
        $service = $this->service($this->config(), $soap);
        $service->setPreferences(true);

        $this->assertSame($response, $service->search($request));
        $this->assertSame([[
            'level'   => 'warning',
            'message' => 'NetSuite REST: operation "search" is not supported, sent via SOAP',
            'context' => ['operation' => 'search'],
        ]], $this->logger->records);
    }

    public function testFallbackFillsSoapDefaultsForRestConfigs()
    {
        $service = $this->service($this->config());

        $values = $this->privateOf($service, 'config');

        $this->assertSame('2025_2', $values['endpoint']);
        $this->assertSame('https://123456-sb1.suitetalk.api.netsuite.com', $values['host']);
    }

    public function testOAuth2OnlyConfigCannotFallBack()
    {
        $service = $this->service([
            'transport'           => 'rest',
            'account'             => '123456',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => '/keys/missing.pem',
            'host'                => 'http://127.0.0.1:1',
        ]);

        try {
            $service->search(new SearchRequest());
            $this->fail('NotSupportedOnRestException expected');
        } catch (NotSupportedOnRestException $e) {
            $this->assertStringContainsString('operation "search" is not supported', $e->getMessage());
        }
        $this->assertNull($this->privateOf($service, 'client'));
    }

    public function testSoapModeIsUnchanged()
    {
        $request = new GetRequest();
        $response = new GetResponse();
        $soap = $this->soap();
        $soap->expects($this->once())->method('__setCookie')->with('JSESSIONID');
        $soap->expects($this->once())
            ->method('__soapCall')
            ->with('get', [$request], null, $this->callback(function ($headers) {
                return array_keys($headers) === ['tokenPassport'];
            }))
            ->willReturn($response);
        $config = $this->config(['transport' => 'soap', 'endpoint' => '2025_2', 'host' => 'https://webservices.sandbox.netsuite.com']);
        $handler = function () {
            $this->fail('soap mode never builds REST handlers');
        };
        $service = $this->service($config, $soap, ['get' => $handler]);

        $this->assertSame($response, $service->get($request));
        $this->assertSame($soap, $service->getClient());
        $this->assertSame([], $this->logger->records);
    }

    public function testNoWsdlRequestForHandledOperations()
    {
        // Building a SoapClient for this host would fail on the WSDL fetch.
        $service = $this->service($this->config(['host' => 'http://127.0.0.1:1']), null, ['get' => function () {
            return new CallbackHandler(function () {
                return new GetResponse();
            });
        }]);

        $this->assertInstanceOf(GetResponse::class, $service->get(new GetRequest()));
        $this->assertInstanceOf(LastCall::class, $service->getClient());
        $this->assertNull($service->getClient()->__getLastRequest());
        $this->assertNull($this->privateOf($service, 'client'));
    }

    public function testGetClientExposesTheLastRestAndSoapCall()
    {
        $soap = $this->soap();
        $soap->method('__getLastRequest')->willReturn('<soap:Envelope/>');
        $soap->method('__getLastResponseHeaders')->willReturn('HTTP/1.1 200 OK');
        $service = $this->service($this->config(), $soap, ['get' => function (callable $restClient) {
            return new CallbackHandler(function () use ($restClient) {
                /** @var RestClient $client */
                $client = $restClient();
                $client->send('GET', '/record/v1/customer/7');
                return new GetResponse();
            });
        }]);
        $service->transport = new FakeTransport([new Response(200, ['Content-Type' => 'application/json'], '{"id":"7"}')]);

        $service->get(new GetRequest());
        $client = $service->getClient();

        $this->assertInstanceOf(LastCall::class, $client);
        $this->assertStringStartsWith(
            "GET https://rest.example.test/services/rest/record/v1/customer/7 HTTP/1.1\r\n",
            $client->__getLastRequestHeaders()
        );
        $this->assertSame('{"id":"7"}', $client->__getLastResponse());

        $service->search(new SearchRequest());

        $this->assertSame('<soap:Envelope/>', $client->__getLastRequest());
        $this->assertSame('HTTP/1.1 200 OK', $client->__getLastResponseHeaders());
    }

    public function testRestHandlersIgnoreSoapHeadersWithADebugLog()
    {
        $service = $this->service($this->config(), $this->soap(), ['get' => function () {
            return new CallbackHandler(function () {
                return new GetResponse();
            });
        }]);
        $service->setPreferences();
        $service->setSearchPreferences();
        $service->setApplicationInfo('app-id');
        $service->addHeader('partnerInfo', 'partner');

        $service->get(new GetRequest());

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('debug', $this->logger->records[0]['level']);
        $this->assertSame(
            'NetSuite REST: operation "get" ignores SOAP headers: preferences, searchPreferences, applicationInfo, partnerInfo',
            $this->logger->records[0]['message']
        );
    }

    /**
     * @dataProvider operationProvider
     */
    public function testEveryOperationReachesAHandlerOrTheFallback(string $operation, bool $handled)
    {
        $request = $this->requestFor($operation);
        $soap = $this->soap();
        $soap->expects($handled ? $this->never() : $this->once())
            ->method('__soapCall')
            ->with($operation, [$request])
            ->willReturn('soap');
        $handlers = [];
        if ($handled) {
            $handlers[$operation] = function () {
                return new CallbackHandler(function () {
                    return 'rest';
                });
            };
        }
        $service = $this->service($this->config(), $soap, $handlers);

        $this->assertSame($handled ? 'rest' : 'soap', $service->$operation($request));
        $this->assertCount($handled ? 0 : 1, $this->logger->records);
    }

    public static function operationProvider(): array
    {
        $cases = [];
        foreach (OperationCatalogTest::serviceOperations() as $operation) {
            $cases[$operation.' via handler'] = [$operation, true];
            $cases[$operation.' via fallback'] = [$operation, false];
        }
        return $cases;
    }

    public function testDefaultHandlersSendEveryOtherOperationToTheFallback()
    {
        $restOperations = [
            'get', 'getList', 'add', 'addList', 'update', 'updateList', 'upsert', 'upsertList', 'delete', 'deleteList',
        ];
        $fallbacks = array_values(array_diff(OperationCatalogTest::serviceOperations(), $restOperations));
        $soap = $this->soap();
        $soap->expects($this->exactly(count($fallbacks)))->method('__soapCall')->willReturn('soap');
        $service = new NetSuiteService($this->config(), [], $soap, $this->logger);

        foreach ($fallbacks as $operation) {
            $this->assertSame('soap', $service->$operation($this->requestFor($operation)));
        }
        $this->assertCount(count($fallbacks), $this->logger->records);
        $this->assertSame($restOperations, array_keys(HandlerFactory::create(function () {
        })));
    }

    private function requestFor(string $operation)
    {
        $type = (new \ReflectionMethod(NetSuiteService::class, $operation))->getParameters()[0]->getType();
        $class = $type->getName();
        return new $class();
    }
}
