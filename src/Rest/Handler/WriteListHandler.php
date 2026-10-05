<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Rest\Response\ResponseBuilder;

/**
 * `addList`, `updateList`, `upsertList`, `deleteList`: the single-record writer per item, in order;
 * a failed item does not stop the rest.
 */
final class WriteListHandler implements OperationHandlerInterface
{
    /** @var RecordWriterInterface */
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
        RecordWriterInterface $writer,
        string $responseClass,
        string $itemProperty,
        ?callable $prepare = null,
        ?ResponseBuilder $responses = null
    ) {
        $this->writer = $writer;
        $this->responseClass = $responseClass;
        $this->itemProperty = $itemProperty;
        $this->prepare = $prepare;
        $this->responses = $responses ?: new ResponseBuilder();
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
            $writes[] = $this->writer->write($item);
        }
        $response = new $this->responseClass();
        $response->writeResponseList = $this->responses->writeList($writes);
        return $response;
    }
}
