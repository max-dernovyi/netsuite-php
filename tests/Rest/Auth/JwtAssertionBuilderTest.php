<?php

namespace tests\Netsuite\Rest\Auth;

use NetSuite\Rest\Auth\JwtAssertionBuilder;
use NetSuite\Rest\Config\RestConfig;
use PHPUnit\Framework\TestCase;

class JwtAssertionBuilderTest extends TestCase
{
    const TOKEN_URL = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/auth/oauth2/v1/token';
    const NOW = 1700000000;

    /** @var string[] PEM private keys by name */
    private static $keys = [];
    /** @var string[] */
    private $tempFiles = [];

    public static function setUpBeforeClass(): void
    {
        $specs = [
            'rsa'       => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
            'rsa-2049'  => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2049],
            'ec-p256'   => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            'ec-p384'   => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1'],
        ];
        foreach ($specs as $name => $spec) {
            $key = openssl_pkey_new($spec);
            openssl_pkey_export($key, $pem);
            self::$keys[$name] = $pem;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    private function builder(string $algorithm, string $key): JwtAssertionBuilder
    {
        return new JwtAssertionBuilder('client-id', 'cert-id', $key, $algorithm, function () {
            return self::NOW;
        });
    }

    /**
     * @return array{0: array, 1: array, 2: string, 3: string} header, claims, signature, signing input
     */
    private static function decode(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        foreach ($parts as $part) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $part, 'base64url without padding');
        }
        return [
            json_decode(self::base64UrlDecode($parts[0]), true),
            json_decode(self::base64UrlDecode($parts[1]), true),
            self::base64UrlDecode($parts[2]),
            $parts[0].'.'.$parts[1],
        ];
    }

    private static function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    private function tempFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'nsjwt');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;
        return $file;
    }

    public function algorithms(): array
    {
        return [
            'PS256' => ['PS256', 'rsa'],
            'ES256' => ['ES256', 'ec-p256'],
        ];
    }

    /**
     * @dataProvider algorithms
     */
    public function testHeaderAndClaims(string $algorithm, string $key)
    {
        list($header, $claims) = self::decode($this->builder($algorithm, self::$keys[$key])->build(self::TOKEN_URL));

        $this->assertSame(['typ' => 'JWT', 'alg' => $algorithm, 'kid' => 'cert-id'], $header);
        $this->assertSame([
            'iss'   => 'client-id',
            'scope' => ['rest_webservices'],
            'aud'   => self::TOKEN_URL,
            'iat'   => self::NOW,
            'exp'   => self::NOW + 3600,
        ], $claims);
    }

    public function testDefaultClockIsCurrentTime()
    {
        $before = time();
        $jwt = (new JwtAssertionBuilder('client-id', 'cert-id', self::$keys['rsa']))->build(self::TOKEN_URL);
        list($header, $claims) = self::decode($jwt);

        $this->assertSame('PS256', $header['alg']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertLessThanOrEqual(time(), $claims['iat']);
        $this->assertLessThanOrEqual($claims['iat'] + 3600, $claims['exp']);
    }

    public function testEs256SignatureIs64BytesAndVerifies()
    {
        list(, , $signature, $input) = self::decode($this->builder('ES256', self::$keys['ec-p256'])->build(self::TOKEN_URL));

        $this->assertSame(64, strlen($signature));
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$keys['ec-p256']))['key'];
        $this->assertSame(1, openssl_verify($input, self::rawEcdsaToDer($signature), $public, OPENSSL_ALGO_SHA256));
        $this->assertSame(0, openssl_verify($input.'x', self::rawEcdsaToDer($signature), $public, OPENSSL_ALGO_SHA256));
    }

    public function testDerSignatureIsConvertedToFixedSizeCoordinates()
    {
        // r has a sign byte (33 bytes), s is short (30 bytes) and must be left-padded.
        $r = "\x00\x80".str_repeat("\x11", 31);
        $s = str_repeat("\x22", 30);
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;

        $method = self::derToRawEcdsa();

        $this->assertSame(
            "\x80".str_repeat("\x11", 31)."\x00\x00".str_repeat("\x22", 30),
            $method->invoke(null, $der, 32)
        );
    }

    public function testMalformedDerSignatureIsRejected()
    {
        $method = self::derToRawEcdsa();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('malformed ECDSA signature');

        $method->invoke(null, "\x30\x06\x02\x01\x01\x03\x01\x01", 32);
    }

    public function rsaKeys(): array
    {
        return [
            '2048-bit modulus' => ['rsa'],
            '2049-bit modulus' => ['rsa-2049'],
        ];
    }

    /**
     * @dataProvider rsaKeys
     */
    public function testPs256PassesReferenceEmsaPssCheck(string $key)
    {
        list(, , $signature, $input) = self::decode($this->builder('PS256', self::$keys[$key])->build(self::TOKEN_URL));

        $this->assertTrue(self::verifyPs256($input, $signature, self::$keys[$key]));
        $this->assertFalse(self::verifyPs256($input.'x', $signature, self::$keys[$key]));
    }

    public function testPs256SignaturesUseRandomSalt()
    {
        $builder = $this->builder('PS256', self::$keys['rsa']);

        $this->assertNotSame(self::decode($builder->build(self::TOKEN_URL))[2], self::decode($builder->build(self::TOKEN_URL))[2]);
    }

    public function testPs256VerifiesWithOpensslCli()
    {
        exec('command -v openssl', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('openssl CLI is not installed');
        }
        list(, , $signature, $input) = self::decode($this->builder('PS256', self::$keys['rsa'])->build(self::TOKEN_URL));
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$keys['rsa']))['key'];

        $cmd = sprintf(
            'openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -verify %s -signature %s %s 2>&1',
            escapeshellarg($this->tempFile($public)),
            escapeshellarg($this->tempFile($signature)),
            escapeshellarg($this->tempFile($input))
        );
        exec($cmd, $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('Verified OK', implode("\n", $output));
    }

    public function testPrivateKeyFromFile()
    {
        $builder = $this->builder('ES256', $this->tempFile(self::$keys['ec-p256']));

        $this->assertSame('ES256', self::decode($builder->build(self::TOKEN_URL))[0]['alg']);
    }

    public function testFromConfig()
    {
        $config = RestConfig::fromArray([
            'transport'           => 'rest',
            'account'             => '123456_SB1',
            'oauth2ClientId'      => 'client-id',
            'oauth2CertificateId' => 'cert-id',
            'oauth2PrivateKey'    => self::$keys['ec-p256'],
            'oauth2Algorithm'     => 'es256',
        ]);

        $jwt = JwtAssertionBuilder::fromConfig($config, function () {
            return self::NOW;
        })->build(self::TOKEN_URL);
        list($header, $claims) = self::decode($jwt);

        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('cert-id', $header['kid']);
        $this->assertSame('client-id', $claims['iss']);
        $this->assertSame(self::NOW, $claims['iat']);
    }

    public function missingKeys(): array
    {
        return [
            'client id'      => [['', 'cert-id', 'KEY'], 'Config key missing: oauth2ClientId'],
            'certificate id' => [['client-id', '', 'KEY'], 'Config key missing: oauth2CertificateId'],
            'private key'    => [['client-id', 'cert-id', ''], 'Config key missing: oauth2PrivateKey'],
        ];
    }

    /**
     * @dataProvider missingKeys
     */
    public function testMissingKeys(array $args, string $message)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        new JwtAssertionBuilder($args[0], $args[1], $args[2] === 'KEY' ? self::$keys['rsa'] : $args[2]);
    }

    public function unsupportedAlgorithms(): array
    {
        return [['RS256'], ['HS256'], ['ES384'], ['PS512'], ['none']];
    }

    /**
     * @dataProvider unsupportedAlgorithms
     */
    public function testUnsupportedAlgorithm(string $algorithm)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('oauth2Algorithm "'.$algorithm.'" is not supported, use PS256 or ES256');

        $this->builder($algorithm, self::$keys['rsa']);
    }

    public function mismatchedKeys(): array
    {
        return [
            'EC key for PS256'    => ['PS256', 'ec-p256', 'must be an RSA key for PS256'],
            'RSA key for ES256'   => ['ES256', 'rsa', 'must be an EC P-256 key for ES256'],
            'P-384 key for ES256' => ['ES256', 'ec-p384', 'must be an EC P-256 key for ES256'],
        ];
    }

    /**
     * @dataProvider mismatchedKeys
     */
    public function testKeyMustMatchAlgorithm(string $algorithm, string $key, string $message)
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->builder($algorithm, self::$keys[$key]);
    }

    public function testInvalidPem()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key invalid: oauth2PrivateKey is not a readable private key');

        $this->builder('PS256', "-----BEGIN PRIVATE KEY-----\nbm90IGEga2V5\n-----END PRIVATE KEY-----\n");
    }

    public function testMissingKeyFile()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Config key invalid: oauth2PrivateKey is neither a PEM key nor a readable file');

        $this->builder('PS256', '/nonexistent/key.pem');
    }

    public function testPublicKeyIsRejected()
    {
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$keys['rsa']))['key'];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not a readable private key');

        $this->builder('PS256', $public);
    }

    private static function derToRawEcdsa(): \ReflectionMethod
    {
        $method = new \ReflectionMethod(JwtAssertionBuilder::class, 'derToRawEcdsa');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        return $method;
    }

    /**
     * Independent EMSA-PSS-VERIFY (RFC 8017 9.1.2) with SHA-256, MGF1-SHA-256 and a 32-byte salt.
     */
    private static function verifyPs256(string $message, string $signature, string $privatePem): bool
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privatePem));
        $modBits = $details['bits'];
        if (strlen($signature) !== (int) ceil($modBits / 8)) {
            return false;
        }
        if (!openssl_public_decrypt($signature, $decrypted, $details['key'], OPENSSL_NO_PADDING)) {
            return false;
        }
        $emBits = $modBits - 1;
        $emLen = (int) ceil($emBits / 8);
        $em = substr($decrypted, -$emLen);
        if (strlen($decrypted) > $emLen && trim(substr($decrypted, 0, -$emLen), "\x00") !== '') {
            return false;
        }

        $hLen = 32;
        $sLen = 32;
        if ($em[$emLen - 1] !== "\xBC") {
            return false;
        }
        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);
        $zeroBits = 8 * $emLen - $emBits;
        if ((ord($maskedDb[0]) >> (8 - $zeroBits)) !== 0) {
            return false;
        }

        $mask = '';
        for ($i = 0; strlen($mask) < strlen($maskedDb); $i++) {
            $mask .= hash('sha256', $h.pack('N', $i), true);
        }
        $db = $maskedDb ^ substr($mask, 0, strlen($maskedDb));
        $db[0] = chr(ord($db[0]) & (0xFF >> $zeroBits));

        $psLen = $emLen - $hLen - $sLen - 2;
        if (substr($db, 0, $psLen) !== str_repeat("\x00", $psLen) || $db[$psLen] !== "\x01") {
            return false;
        }
        $salt = substr($db, -$sLen);
        $expected = hash('sha256', str_repeat("\x00", 8).hash('sha256', $message, true).$salt, true);

        return hash_equals($expected, $h);
    }

    private static function rawEcdsaToDer(string $raw): string
    {
        $der = '';
        foreach (str_split($raw, 32) as $int) {
            $int = ltrim($int, "\x00");
            if ($int === '' || ord($int[0]) & 0x80) {
                $int = "\x00".$int;
            }
            $der .= "\x02".chr(strlen($int)).$int;
        }
        return "\x30".chr(strlen($der)).$der;
    }
}
