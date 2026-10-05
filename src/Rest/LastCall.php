<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest;

use NetSuite\Rest\Http\CallRecorder;

/**
 * What `getClient()` returns in rest mode: SoapClient-style accessors for the last call, REST or SOAP.
 */
final class LastCall
{
    /** @var CallRecorder */
    private $recorder;
    /** @var callable(): (\SoapClient|null) */
    private $soapClient;
    /** @var bool */
    private $soap = false;

    /**
     * @param callable(): (\SoapClient|null) $soapClient the SOAP client used by the fallback, if created
     */
    public function __construct(CallRecorder $recorder, callable $soapClient)
    {
        $this->recorder = $recorder;
        $this->soapClient = $soapClient;
    }

    public function getRecorder(): CallRecorder
    {
        return $this->recorder;
    }

    public function startRest(): void
    {
        $this->soap = false;
        $this->recorder->clear();
    }

    public function startSoap(): void
    {
        $this->soap = true;
    }

    public function __getLastRequest(): ?string
    {
        return $this->soap ? $this->fromSoap('__getLastRequest') : $this->recorder->getLastRequestBody();
    }

    public function __getLastResponse(): ?string
    {
        return $this->soap ? $this->fromSoap('__getLastResponse') : $this->recorder->getLastResponseBody();
    }

    public function __getLastRequestHeaders(): ?string
    {
        return $this->soap ? $this->fromSoap('__getLastRequestHeaders') : $this->recorder->getLastRequestHeaders();
    }

    public function __getLastResponseHeaders(): ?string
    {
        return $this->soap ? $this->fromSoap('__getLastResponseHeaders') : $this->recorder->getLastResponseHeaders();
    }

    private function fromSoap(string $method): ?string
    {
        $client = call_user_func($this->soapClient);
        return $client !== null ? $client->$method() : null;
    }
}
