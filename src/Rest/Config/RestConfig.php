<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Config;

final class RestConfig
{
    const TRANSPORT_SOAP = 'soap';
    const TRANSPORT_REST = 'rest';

    const AUTH_TBA = 'tba';
    const AUTH_OAUTH2 = 'oauth2';

    const DEFAULT_TIMEOUT = 60;
    const DEFAULT_MAX_ATTEMPTS = 3;
    const DEFAULT_SIGNATURE_ALGORITHM = 'sha256';
    const DEFAULT_OAUTH2_ALGORITHM = 'PS256';

    const TBA_KEYS = ['consumerKey', 'consumerSecret', 'token', 'tokenSecret'];
    const OAUTH2_KEYS = ['oauth2ClientId', 'oauth2CertificateId', 'oauth2PrivateKey'];

    /** @var string */
    private $transport;
    /** @var string */
    private $realm;
    /** @var string */
    private $host;
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $authType;
    /** @var array<string, string> */
    private $tbaKeys = [];
    /** @var string */
    private $signatureAlgorithm;
    /** @var array<string, string> */
    private $oauth2Keys = [];
    /** @var string */
    private $oauth2Algorithm;
    /** @var int|float */
    private $timeout;
    /** @var int */
    private $maxAttempts;

    private function __construct()
    {
    }

    /**
     * @throws \RuntimeException on an invalid or incomplete config
     */
    public static function fromArray(array $config): self
    {
        $self = new self();
        $self->transport = self::transportOf($config);

        if (!self::filled($config, 'account')) {
            throw new \RuntimeException('Config key missing: account');
        }
        list($self->realm, $self->host) = self::parseAccount((string) $config['account']);

        $self->baseUrl = self::filled($config, 'restBaseUrl')
            ? rtrim((string) $config['restBaseUrl'], '/')
            : 'https://'.$self->host.'.suitetalk.api.netsuite.com/services/rest';

        $self->authType = self::detectAuth($config);
        foreach (self::TBA_KEYS as $key) {
            if (self::filled($config, $key)) {
                $self->tbaKeys[$key] = (string) $config[$key];
            }
        }
        foreach (self::OAUTH2_KEYS as $key) {
            if (self::filled($config, $key)) {
                $self->oauth2Keys[$key] = (string) $config[$key];
            }
        }
        $self->signatureAlgorithm = self::filled($config, 'signatureAlgorithm')
            ? (string) $config['signatureAlgorithm']
            : self::DEFAULT_SIGNATURE_ALGORITHM;
        $self->oauth2Algorithm = self::filled($config, 'oauth2Algorithm')
            ? strtoupper((string) $config['oauth2Algorithm'])
            : self::DEFAULT_OAUTH2_ALGORITHM;

        $self->timeout = self::positiveNumber($config, 'timeout', self::DEFAULT_TIMEOUT);
        $self->maxAttempts = (int) self::positiveNumber($config, 'maxAttempts', self::DEFAULT_MAX_ATTEMPTS, true);

        return $self;
    }

    /**
     * @throws \RuntimeException on an unknown transport
     */
    public static function transportOf(array $config): string
    {
        if (!isset($config['transport']) || $config['transport'] === '') {
            return self::TRANSPORT_SOAP;
        }
        $transport = is_string($config['transport']) ? strtolower(trim($config['transport'])) : null;
        if ($transport !== self::TRANSPORT_SOAP && $transport !== self::TRANSPORT_REST) {
            throw new \RuntimeException('Config key invalid: transport must be "soap" or "rest"');
        }
        return $transport;
    }

    /**
     * Returns [realm, host]: `123456_SB1` and `123456-sb1` for any spelling of a sandbox id.
     *
     * @return string[]
     * @throws \RuntimeException on an invalid account id
     */
    public static function parseAccount(string $account): array
    {
        $realm = strtoupper(str_replace('-', '_', trim($account)));
        if (!preg_match('/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/', $realm)) {
            throw new \RuntimeException('Config key invalid: account "'.$account.'" is not a NetSuite account id');
        }
        return [$realm, strtolower(str_replace('_', '-', $realm))];
    }

    private static function detectAuth(array $config): string
    {
        $oauth2Missing = self::missingKeys($config, self::OAUTH2_KEYS);
        if (!$oauth2Missing) {
            return self::AUTH_OAUTH2;
        }
        $tbaMissing = self::missingKeys($config, self::TBA_KEYS);
        if (!$tbaMissing) {
            return self::AUTH_TBA;
        }
        // A partial OAuth 2.0 set shows intent, so report its missing key rather than a TBA one.
        $partialOAuth2 = count($oauth2Missing) < count(self::OAUTH2_KEYS);
        throw new \RuntimeException('Config key missing: '.($partialOAuth2 ? $oauth2Missing[0] : $tbaMissing[0]));
    }

    /**
     * @return string[]
     */
    private static function missingKeys(array $config, array $keys): array
    {
        return array_values(array_filter($keys, function ($key) use ($config) {
            return !self::filled($config, $key);
        }));
    }

    private static function filled(array $config, string $key): bool
    {
        return isset($config[$key]) && !empty($config[$key]);
    }

    /**
     * @return int|float
     */
    private static function positiveNumber(array $config, string $key, $default, bool $integer = false)
    {
        if (!isset($config[$key]) || $config[$key] === '') {
            return $default;
        }
        $value = $config[$key];
        $valid = $integer
            ? filter_var($value, FILTER_VALIDATE_INT) !== false
            : is_numeric($value);
        if (!$valid || $value <= 0) {
            throw new \RuntimeException('Config key invalid: '.$key.' must be a positive '.($integer ? 'integer' : 'number'));
        }
        return $integer ? (int) $value : $value + 0;
    }

    public function transport(): string
    {
        return $this->transport;
    }

    public function isRest(): bool
    {
        return $this->transport === self::TRANSPORT_REST;
    }

    /**
     * The account id as NetSuite expects it in the OAuth realm, e.g. `123456_SB1`.
     */
    public function realm(): string
    {
        return $this->realm;
    }

    /**
     * The account id as used in hostnames, e.g. `123456-sb1`.
     */
    public function host(): string
    {
        return $this->host;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function authType(): string
    {
        return $this->authType;
    }

    /**
     * Whether a full TBA key set is present, which the SOAP fallback needs.
     */
    public function hasTbaKeys(): bool
    {
        return count($this->tbaKeys) === count(self::TBA_KEYS);
    }

    public function consumerKey(): ?string
    {
        return $this->tbaKeys['consumerKey'] ?? null;
    }

    public function consumerSecret(): ?string
    {
        return $this->tbaKeys['consumerSecret'] ?? null;
    }

    public function token(): ?string
    {
        return $this->tbaKeys['token'] ?? null;
    }

    public function tokenSecret(): ?string
    {
        return $this->tbaKeys['tokenSecret'] ?? null;
    }

    public function signatureAlgorithm(): string
    {
        return $this->signatureAlgorithm;
    }

    public function oauth2ClientId(): ?string
    {
        return $this->oauth2Keys['oauth2ClientId'] ?? null;
    }

    public function oauth2CertificateId(): ?string
    {
        return $this->oauth2Keys['oauth2CertificateId'] ?? null;
    }

    /**
     * PEM contents or a path to a PEM file.
     */
    public function oauth2PrivateKey(): ?string
    {
        return $this->oauth2Keys['oauth2PrivateKey'] ?? null;
    }

    public function oauth2Algorithm(): string
    {
        return $this->oauth2Algorithm;
    }

    /**
     * Total request timeout in seconds.
     *
     * @return int|float
     */
    public function timeout()
    {
        return $this->timeout;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }
}
