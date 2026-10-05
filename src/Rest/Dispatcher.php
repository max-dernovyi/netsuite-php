<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest;

use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Handler\OperationHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Routes a NetSuiteService operation to its REST handler, or to SOAP when it has none.
 */
final class Dispatcher
{
    const FALLBACK_WARNING = 'NetSuite REST: operation "%s" is not supported, sent via SOAP';

    /** @var array<string, callable(): OperationHandlerInterface> */
    private $factories;
    /** @var array<string, OperationHandlerInterface> */
    private $handlers = [];
    /** @var callable(string, object): object|null */
    private $soapFallback;
    /** @var LastCall */
    private $lastCall;
    /** @var LoggerInterface */
    private $logger;

    /**
     * @param array<string, callable(): OperationHandlerInterface> $handlers operation => lazy handler
     * @param callable|null $soapFallback `fn(string $operation, object $request): object`; null when SOAP is unavailable
     */
    public function __construct(
        array $handlers,
        ?callable $soapFallback,
        LastCall $lastCall,
        ?LoggerInterface $logger = null
    ) {
        $this->factories = $handlers;
        $this->soapFallback = $soapFallback;
        $this->lastCall = $lastCall;
        $this->logger = $logger ?: new NullLogger();
    }

    public function handles(string $operation): bool
    {
        return isset($this->factories[$operation]);
    }

    /**
     * @param object   $request
     * @param string[] $soapHeaders names of the SOAP headers set on the client, which REST ignores
     * @return object
     * @throws NotSupportedOnRestException when the operation has no handler and SOAP is unavailable
     */
    public function dispatch(string $operation, $request, array $soapHeaders = [])
    {
        if ($this->handles($operation)) {
            if ($soapHeaders) {
                $this->logger->debug(
                    sprintf('NetSuite REST: operation "%s" ignores SOAP headers: %s', $operation, implode(', ', $soapHeaders)),
                    ['operation' => $operation]
                );
            }
            $this->lastCall->startRest();
            return $this->handler($operation)->handle($request);
        }

        if ($this->soapFallback === null) {
            throw new NotSupportedOnRestException(sprintf(
                'NetSuite REST: operation "%s" is not supported (REST status: %s) and cannot fall back to SOAP without TBA keys',
                $operation,
                OperationCatalog::status($operation)
            ));
        }
        $this->logger->warning(sprintf(self::FALLBACK_WARNING, $operation), ['operation' => $operation]);
        $this->lastCall->startSoap();
        return call_user_func($this->soapFallback, $operation, $request);
    }

    private function handler(string $operation): OperationHandlerInterface
    {
        if (!isset($this->handlers[$operation])) {
            $this->handlers[$operation] = call_user_func($this->factories[$operation]);
        }
        return $this->handlers[$operation];
    }
}
