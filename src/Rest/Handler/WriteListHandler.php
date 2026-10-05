<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Response\ResponseBuilder;

/**
 * `addList`, `updateList`, `upsertList`, `deleteList`: the single-record writer per item, in order;
 * a failed item does not stop the rest. A `RestFault` on the first item is thrown, as SOAP would.
 */
final class WriteListHandler implements OperationHandlerInterface
{
    /** @var AbstractWriteHandler */
    private $writer;
    /** @var string */
    private $responseClass;
    /** @var string */
    private $itemProperty;
    /** @var callable|null */
    private $prepare;
    /** @var ResponseBuilder */
    private $responses;

    /**
     * @param string $responseClass the generated `*ListResponse` class
     * @param string $itemProperty the request property holding the items (`record` or `baseRef`)
     * @param callable|null $prepare `fn(object $request): void`, called once before the items
     */
    public function __construct(
        AbstractWriteHandler $writer,
        string $responseClass,
        string $itemProperty,
        ?callable $prepare = null
    ) {
        $this->writer = $writer;
        $this->responseClass = $responseClass;
        $this->itemProperty = $itemProperty;
        $this->prepare = $prepare;
        $this->responses = new ResponseBuilder();
    }

    public function handle($request)
    {
        if ($this->prepare !== null) {
            call_user_func($this->prepare, $request);
        }
        $items = $request->{$this->itemProperty};
        if ($items === null) {
            $items = [];
        } elseif (!is_array($items)) {
            $items = [$items];
        }
        $writes = [];
        foreach ($items as $item) {
            try {
                $writes[] = $this->writer->write($item);
            } catch (RestFault $e) {
                if (!$writes) {
                    throw $e;
                }
                $writes[] = $this->responses->writeFault($e);
            } catch (NotSupportedOnRestException $e) {
                $writes[] = $this->responses->writeFault($e);
            }
        }
        $response = new $this->responseClass();
        $response->writeResponseList = $this->responses->writeList($writes);
        return $response;
    }
}
