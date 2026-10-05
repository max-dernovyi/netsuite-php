<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Auth;

use NetSuite\Rest\Config\RestConfig;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Http\Request;
use NetSuite\Rest\Http\TransportInterface;

/**
 * OAuth 2.0 client credentials: Bearer tokens, cached until shortly before they expire.
 */
final class OAuth2Authenticator implements AuthenticatorInterface
{
    /** Seconds before expiry when a cached token is replaced. */
    const REFRESH_MARGIN = 60;

    /** @var JwtAssertionBuilder */
    private $assertions;
    /** @var OAuth2TokenClient */
    private $tokens;
    /** @var TokenStoreInterface */
    private $store;
    /** @var string */
    private $storeKey;
    /** @var callable(): int */
    private $clock;

    /**
     * @param callable|null $clock returns the current Unix time; time() by default
     */
    public function __construct(
        JwtAssertionBuilder $assertions,
        OAuth2TokenClient $tokens,
        ?TokenStoreInterface $store = null,
        string $storeKey = 'netsuite_oauth2',
        ?callable $clock = null
    ) {
        $this->assertions = $assertions;
        $this->tokens = $tokens;
        $this->store = $store ?: new InMemoryTokenStore();
        $this->storeKey = $storeKey;
        $this->clock = $clock ?: 'time';
    }

    /**
     * @throws \RuntimeException on a missing or invalid OAuth 2.0 key
     */
    public static function fromConfig(
        RestConfig $config,
        TransportInterface $transport,
        ?TokenStoreInterface $store = null,
        ?callable $clock = null
    ): self {
        return new self(
            JwtAssertionBuilder::fromConfig($config, $clock),
            OAuth2TokenClient::fromConfig($config, $transport, $clock),
            $store,
            self::storeKey($config),
            $clock
        );
    }

    /**
     * A key per account and integration, safe for PSR-16 caches.
     */
    public static function storeKey(RestConfig $config): string
    {
        return 'netsuite_oauth2_'.sha1($config->realm().'|'.$config->oauth2ClientId().'|'.$config->oauth2CertificateId());
    }

    /**
     * @throws RestFault          when NetSuite refuses the token request
     * @throws TransportException when the token endpoint cannot be reached
     */
    public function authorize(Request $request): Request
    {
        $token = $this->store->get($this->storeKey);
        if ($token === null || (int) call_user_func($this->clock) >= $token->getExpiresAt() - self::REFRESH_MARGIN) {
            $token = $this->tokens->requestToken($this->assertions->build($this->tokens->getTokenUrl()));
            $this->store->set($this->storeKey, $token);
        }

        return $request->withHeader('Authorization', 'Bearer '.$token->getValue());
    }

    public function invalidate(): void
    {
        $this->store->delete($this->storeKey);
    }
}
