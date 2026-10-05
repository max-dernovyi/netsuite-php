<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Http\CurlTransport;
use NetSuite\Rest\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * @requires extension curl
 */
class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    /** @var string */
    private static $baseUrl;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();
        $command = sprintf(
            '%s -S 127.0.0.1:%d %s',
            escapeshellarg(PHP_BINARY),
            $port,
            escapeshellarg(__DIR__ . '/../Fixtures/server.php')
        );
        $env = array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '4']);
        self::$server = proc_open('exec ' . $command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $env);
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(50000);
        }
        self::fail('PHP built-in web server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function echoRequest(string $method, ?string $body = null, array $headers = []): array
    {
        $response = (new CurlTransport(5))->send(new Request($method, self::$baseUrl . '/echo?a=1&b=x%20y', $headers, $body));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeader('content-type'));
        return $response->json();
    }

    public function methodProvider(): array
    {
        return [['GET'], ['POST'], ['PUT'], ['PATCH'], ['DELETE']];
    }

    /**
     * @dataProvider methodProvider
     */
    public function testMethodWithoutBody(string $method)
    {
        $echo = $this->echoRequest($method);

        $this->assertSame($method, $echo['method']);
        $this->assertSame('/echo?a=1&b=x%20y', $echo['uri']);
        $this->assertSame('', $echo['body']);
    }

    /**
     * @dataProvider methodProvider
     */
    public function testMethodWithBody(string $method)
    {
        $body = '{"companyName":"Acme"}';
        $echo = $this->echoRequest($method, $body, ['Content-Type' => 'application/json']);

        $this->assertSame($method, $echo['method']);
        $this->assertSame($body, $echo['body']);
        $this->assertSame('application/json', $echo['headers']['content-type']);
    }

    public function testRequestHeadersAreSent()
    {
        $echo = $this->echoRequest('GET', null, [
            'Authorization' => 'OAuth realm="123456_SB1"',
            'Prefer'        => 'transient',
        ]);

        $this->assertSame('OAuth realm="123456_SB1"', $echo['headers']['authorization']);
        $this->assertSame('transient', $echo['headers']['prefer']);
    }

    public function testLargeBody()
    {
        $body = json_encode(['memo' => str_repeat('a', 100000)]);
        $echo = $this->echoRequest('POST', $body, ['Content-Type' => 'application/json']);

        $this->assertSame($body, $echo['body']);
    }

    public function testStatusAndResponseHeaders()
    {
        $response = (new CurlTransport(5))->send(new Request('POST', self::$baseUrl . '/status/204', [], '{}'));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        $this->assertSame('https://example.test/record/v1/customer/42', $response->getHeader('location'));
        $this->assertSame('a, b', $response->getHeader('X-Multi'));
    }

    public function testErrorStatusIsAResponse()
    {
        $response = (new CurlTransport(5))->send(new Request('GET', self::$baseUrl . '/status/503'));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->isSuccessful());
    }

    public function testTimeout()
    {
        $transport = new CurlTransport(0.3);
        $start = microtime(true);
        try {
            $transport->send(new Request('GET', self::$baseUrl . '/sleep?ms=2000'));
            $this->fail('Expected TransportException');
        } catch (TransportException $e) {
            $this->assertSame(CURLE_OPERATION_TIMEOUTED, $e->getCode());
            $this->assertStringContainsString('GET ' . self::$baseUrl . '/sleep', $e->getMessage());
        }
        $this->assertLessThan(2.0, microtime(true) - $start, 'curl gave up before the 2 s response');
    }

    public function testRefusedConnection()
    {
        $url = 'http://127.0.0.1:' . self::freePort() . '/';

        $this->expectException(TransportException::class);
        $this->expectExceptionCode(CURLE_COULDNT_CONNECT);
        (new CurlTransport(2))->send(new Request('GET', $url));
    }

    public function testInvalidTimeout()
    {
        $this->expectException(\InvalidArgumentException::class);
        new CurlTransport(0);
    }
}
