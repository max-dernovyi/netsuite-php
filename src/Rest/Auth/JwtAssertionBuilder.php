<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

use NetSuite\Rest\Config\RestConfig;

/**
 * Builds the signed JWT client assertion for the OAuth 2.0 client credentials flow.
 */
final class JwtAssertionBuilder
{
    const ALGORITHMS = ['PS256', 'ES256'];
    const SCOPE = ['rest_webservices'];
    /** NetSuite rejects an `exp` more than 3600 s after `iat`. */
    const LIFETIME = 3600;

    const HASH_LENGTH = 32;
    const ES256_CURVE = 'prime256v1';
    const ES256_COORDINATE_LENGTH = 32;

    /** @var string */
    private $clientId;
    /** @var string */
    private $certificateId;
    /** @var resource|\OpenSSLAsymmetricKey */
    private $key;
    /** @var int */
    private $keyBits;
    /** @var string */
    private $algorithm;
    /** @var callable(): int */
    private $clock;

    /**
     * @param string        $privateKey PEM contents or a path to a PEM file
     * @param callable|null $clock      returns the current Unix time; time() by default
     * @throws \RuntimeException on a missing key, an unreadable private key or an unsupported algorithm
     */
    public function __construct(
        string $clientId,
        string $certificateId,
        string $privateKey,
        string $algorithm = RestConfig::DEFAULT_OAUTH2_ALGORITHM,
        ?callable $clock = null
    ) {
        $keys = [
            'oauth2ClientId'      => $clientId,
            'oauth2CertificateId' => $certificateId,
            'oauth2PrivateKey'    => $privateKey,
        ];
        foreach ($keys as $name => $value) {
            if ($value === '') {
                throw new \RuntimeException('Config key missing: '.$name);
            }
        }
        if (!in_array(strtoupper($algorithm), self::ALGORITHMS, true)) {
            throw new \RuntimeException(
                'Config key invalid: oauth2Algorithm "'.$algorithm.'" is not supported, use '.implode(' or ', self::ALGORITHMS)
            );
        }

        $this->clientId = $clientId;
        $this->certificateId = $certificateId;
        $this->algorithm = strtoupper($algorithm);
        $this->clock = $clock ?: 'time';
        $this->loadKey($privateKey);
    }

    /**
     * @throws \RuntimeException on a missing or invalid OAuth 2.0 key
     */
    public static function fromConfig(RestConfig $config, ?callable $clock = null): self
    {
        return new self(
            (string) $config->oauth2ClientId(),
            (string) $config->oauth2CertificateId(),
            (string) $config->oauth2PrivateKey(),
            $config->oauth2Algorithm(),
            $clock
        );
    }

    /**
     * @param string $audience the token endpoint URL
     */
    public function build(string $audience): string
    {
        $now = (int) call_user_func($this->clock);
        $header = ['typ' => 'JWT', 'alg' => $this->algorithm, 'kid' => $this->certificateId];
        $claims = [
            'iss'   => $this->clientId,
            'scope' => self::SCOPE,
            'aud'   => $audience,
            'iat'   => $now,
            'exp'   => $now + self::LIFETIME,
        ];

        $input = self::base64Url(self::json($header)).'.'.self::base64Url(self::json($claims));
        $signature = $this->algorithm === 'ES256' ? $this->signEs256($input) : $this->signPs256($input);

        return $input.'.'.self::base64Url($signature);
    }

    private function loadKey(string $pemOrPath): void
    {
        $pem = $pemOrPath;
        if (strpos($pemOrPath, '-----BEGIN') === false) {
            if (!is_file($pemOrPath) || !is_readable($pemOrPath)) {
                throw new \RuntimeException('Config key invalid: oauth2PrivateKey is neither a PEM key nor a readable file');
            }
            $pem = (string) file_get_contents($pemOrPath);
        }

        $key = openssl_pkey_get_private($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        self::clearOpensslErrors();
        if ($details === false) {
            throw new \RuntimeException('Config key invalid: oauth2PrivateKey is not a readable private key');
        }

        $valid = $this->algorithm === 'ES256'
            ? $details['type'] === OPENSSL_KEYTYPE_EC && ($details['ec']['curve_name'] ?? '') === self::ES256_CURVE
            : $details['type'] === OPENSSL_KEYTYPE_RSA;
        if (!$valid) {
            throw new \RuntimeException(
                'Config key invalid: oauth2PrivateKey must be '
                .($this->algorithm === 'ES256' ? 'an EC P-256' : 'an RSA').' key for '.$this->algorithm
            );
        }

        $this->key = $key;
        $this->keyBits = (int) $details['bits'];
    }

    private function signEs256(string $input): string
    {
        if (!openssl_sign($input, $der, $this->key, OPENSSL_ALGO_SHA256)) {
            self::clearOpensslErrors();
            throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion');
        }
        return self::derToRawEcdsa($der, self::ES256_COORDINATE_LENGTH);
    }

    /**
     * RSASSA-PSS with SHA-256, MGF1-SHA-256 and a 32-byte salt (RFC 7518 3.5). openssl_sign() has no PSS
     * option, so the EMSA-PSS encoding is built here and signed with raw RSA.
     */
    private function signPs256(string $input): string
    {
        $em = self::emsaPssEncode($input, $this->keyBits - 1, random_bytes(self::HASH_LENGTH));
        $em = str_pad($em, (int) ceil($this->keyBits / 8), "\x00", STR_PAD_LEFT);
        if (!openssl_private_encrypt($em, $signature, $this->key, OPENSSL_NO_PADDING)) {
            self::clearOpensslErrors();
            throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion');
        }
        return $signature;
    }

    /**
     * EMSA-PSS-ENCODE from RFC 8017 9.1.1 with SHA-256.
     */
    private static function emsaPssEncode(string $message, int $emBits, string $salt): string
    {
        $emLen = (int) ceil($emBits / 8);
        $hLen = self::HASH_LENGTH;
        $sLen = strlen($salt);
        if ($emLen < $hLen + $sLen + 2) {
            throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion: the RSA key is too short');
        }

        $h = hash('sha256', str_repeat("\x00", 8).hash('sha256', $message, true).$salt, true);
        $db = str_repeat("\x00", $emLen - $sLen - $hLen - 2)."\x01".$salt;
        $maskedDb = $db ^ self::mgf1($h, $emLen - $hLen - 1);
        $maskedDb[0] = chr(ord($maskedDb[0]) & (0xFF >> (8 * $emLen - $emBits)));

        return $maskedDb.$h."\xBC";
    }

    private static function mgf1(string $seed, int $length): string
    {
        $mask = '';
        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash('sha256', $seed.pack('N', $counter), true);
        }
        return substr($mask, 0, $length);
    }

    /**
     * Converts a DER ECDSA-Sig-Value (SEQUENCE of INTEGER r, s) into the fixed-size r‖s form JWS needs.
     */
    private static function derToRawEcdsa(string $der, int $length): string
    {
        $offset = 0;
        self::readDerHeader($der, $offset, 0x30);
        $raw = '';
        for ($i = 0; $i < 2; $i++) {
            $intLength = self::readDerHeader($der, $offset, 0x02);
            $int = ltrim(substr($der, $offset, $intLength), "\x00");
            $offset += $intLength;
            if (strlen($int) > $length) {
                throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion: malformed ECDSA signature');
            }
            $raw .= str_pad($int, $length, "\x00", STR_PAD_LEFT);
        }
        return $raw;
    }

    /**
     * Reads a DER tag and length at $offset, moves $offset to the value and returns the value length.
     */
    private static function readDerHeader(string $der, int &$offset, int $tag): int
    {
        if (!isset($der[$offset + 1]) || ord($der[$offset]) !== $tag) {
            throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion: malformed ECDSA signature');
        }
        $length = ord($der[$offset + 1]);
        $offset += 2;
        if ($length & 0x80) {
            $bytes = $length & 0x7F;
            $length = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $length = ($length << 8) | ord($der[$offset++] ?? "\x00");
            }
        }
        if ($offset + $length > strlen($der)) {
            throw new \RuntimeException('Cannot sign the OAuth 2.0 client assertion: malformed ECDSA signature');
        }
        return $length;
    }

    private static function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function clearOpensslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }
}
