<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

/**
 * Keeps the last HTTP exchange as sent, for the SoapClient-style __getLast*() accessors.
 */
final class CallRecorder
{
    /** @var Request|null */
    private $request;
    /** @var Response|null */
    private $response;

    /**
     * @param Response|null $response null when no HTTP response was received
     */
    public function record(Request $request, ?Response $response): void
    {
        $this->request = $request;
        $this->response = $response;
    }

    public function clear(): void
    {
        $this->request = null;
        $this->response = null;
    }

    public function getLastRequest(): ?Request
    {
        return $this->request;
    }

    public function getLastResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * The request line and headers, e.g. "GET https://… HTTP/1.1\r\nAccept: application/json".
     */
    public function getLastRequestHeaders(): ?string
    {
        if ($this->request === null) {
            return null;
        }
        return implode("\r\n", array_merge(
            [$this->request->getMethod().' '.$this->request->getUrl().' HTTP/1.1'],
            $this->request->getHeaderLines()
        ));
    }

    public function getLastRequestBody(): ?string
    {
        return $this->request !== null ? $this->request->getBody() : null;
    }

    /**
     * The status line and headers, e.g. "HTTP/1.1 204\r\nLocation: …".
     */
    public function getLastResponseHeaders(): ?string
    {
        if ($this->response === null) {
            return null;
        }
        $lines = ['HTTP/1.1 '.$this->response->getStatusCode()];
        foreach ($this->response->getHeaders() as $name => $value) {
            $lines[] = $name.': '.$value;
        }
        return implode("\r\n", $lines);
    }

    public function getLastResponseBody(): ?string
    {
        return $this->response !== null ? $this->response->getBody() : null;
    }
}
