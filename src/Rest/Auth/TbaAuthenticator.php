<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Http\Request;

/**
 * Token-based authentication: OAuth 1.0a with HMAC-SHA256.
 */
final class TbaAuthenticator implements AuthenticatorInterface
{
    const SIGNATURE_METHOD = 'HMAC-SHA256';

    /** @var string */
    private $realm;
    /** @var string */
    private $consumerKey;
    /** @var string */
    private $consumerSecret;
    /** @var string */
    private $token;
    /** @var string */
    private $tokenSecret;
    /** @var callable(): string */
    private $nonceProvider;
    /** @var callable(): int|string */
    private $timestampProvider;

    /**
     * @param string        $realm             account id in its exact spelling, e.g. `123456_SB1`
     * @param callable|null $nonceProvider     returns the oauth_nonce; random by default
     * @param callable|null $timestampProvider returns the oauth_timestamp; time() by default
     * @throws \RuntimeException on a missing key or an algorithm other than sha256
     */
    public function __construct(
        string $realm,
        string $consumerKey,
        string $consumerSecret,
        string $token,
        string $tokenSecret,
        string $signatureAlgorithm = RestConfig::DEFAULT_SIGNATURE_ALGORITHM,
        ?callable $nonceProvider = null,
        ?callable $timestampProvider = null
    ) {
        $keys = [
            'account'        => $realm,
            'consumerKey'    => $consumerKey,
            'consumerSecret' => $consumerSecret,
            'token'          => $token,
            'tokenSecret'    => $tokenSecret,
        ];
        foreach ($keys as $name => $value) {
            if ($value === '') {
                throw new \RuntimeException('Config key missing: '.$name);
            }
        }
        if (strtolower($signatureAlgorithm) !== 'sha256') {
            throw new \RuntimeException(
                'Config key invalid: signatureAlgorithm "'.$signatureAlgorithm.'" is not supported by REST, use sha256'
            );
        }

        $this->realm = $realm;
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->token = $token;
        $this->tokenSecret = $tokenSecret;
        $this->nonceProvider = $nonceProvider ?: function () {
            return bin2hex(random_bytes(16));
        };
        $this->timestampProvider = $timestampProvider ?: 'time';
    }

    /**
     * @throws \RuntimeException on a missing TBA key or an unsupported algorithm
     */
    public static function fromConfig(
        RestConfig $config,
        ?callable $nonceProvider = null,
        ?callable $timestampProvider = null
    ): self {
        return new self(
            $config->realm(),
            (string) $config->consumerKey(),
            (string) $config->consumerSecret(),
            (string) $config->token(),
            (string) $config->tokenSecret(),
            $config->signatureAlgorithm(),
            $nonceProvider,
            $timestampProvider
        );
    }

    public function authorize(Request $request): Request
    {
        $oauth = [
            'oauth_consumer_key'     => $this->consumerKey,
            'oauth_nonce'            => (string) call_user_func($this->nonceProvider),
            'oauth_signature_method' => self::SIGNATURE_METHOD,
            'oauth_timestamp'        => (string) call_user_func($this->timestampProvider),
            'oauth_token'            => $this->token,
            'oauth_version'          => '1.0',
        ];
        $oauth['oauth_signature'] = $this->sign($request, $oauth);

        // NetSuite rejects an encoded realm, so `123456_SB1` goes in verbatim.
        $parts = ['realm="'.$this->realm.'"'];
        foreach ($oauth as $name => $value) {
            $parts[] = $name.'="'.rawurlencode($value).'"';
        }

        return $request->withHeader('Authorization', 'OAuth '.implode(', ', $parts));
    }

    public function invalidate(): void
    {
    }

    /**
     * @param array<string, string> $oauth
     */
    private function sign(Request $request, array $oauth): string
    {
        list($baseUrl, $query) = self::splitUrl($request->getUrl());

        $params = [];
        foreach (array_merge(self::parseQuery($query), self::pairs($oauth)) as $pair) {
            $params[] = [rawurlencode($pair[0]), rawurlencode($pair[1])];
        }
        usort($params, function (array $a, array $b) {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });
        $normalized = implode('&', array_map(function (array $pair) {
            return $pair[0].'='.$pair[1];
        }, $params));

        $baseString = implode('&', [
            $request->getMethod(),
            rawurlencode($baseUrl),
            rawurlencode($normalized),
        ]);
        $key = rawurlencode($this->consumerSecret).'&'.rawurlencode($this->tokenSecret);

        return base64_encode(hash_hmac('sha256', $baseString, $key, true));
    }

    /**
     * Returns [base URI per RFC 5849 3.4.1.2, raw query string].
     *
     * @return string[]
     */
    private static function splitUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Cannot sign a request to "'.$url.'": not an absolute URL');
        }
        $scheme = strtolower($parts['scheme']);
        $base = $scheme.'://'.strtolower($parts['host']);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && $parts['port'] !== $defaultPort) {
            $base .= ':'.$parts['port'];
        }
        $base .= $parts['path'] ?? '/';

        return [$base, $parts['query'] ?? ''];
    }

    /**
     * Splits a query string into decoded [name, value] pairs; unlike parse_str() it keeps
     * repeated names, dots and brackets as they are.
     *
     * @return array<int, string[]>
     */
    private static function parseQuery(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $item) {
            if ($item === '') {
                continue;
            }
            $kv = explode('=', $item, 2);
            $pairs[] = [urldecode($kv[0]), urldecode($kv[1] ?? '')];
        }
        return $pairs;
    }

    /**
     * @param array<string, string> $map
     * @return array<int, string[]>
     */
    private static function pairs(array $map): array
    {
        $pairs = [];
        foreach ($map as $name => $value) {
            $pairs[] = [$name, $value];
        }
        return $pairs;
    }
}
