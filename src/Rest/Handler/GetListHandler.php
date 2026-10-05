<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\GetListResponse;
use NetSuite\Rest\Exception\NotSupportedOnRestException;
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Response\ResponseBuilder;

/**
 * `getList`: one `get` per reference, in order; a failed item does not stop the rest.
 * A `RestFault` on the first item is thrown, as SOAP would.
 */
final class GetListHandler implements OperationHandlerInterface
{
    /** @var GetHandler */
    private $get;
    /** @var ResponseBuilder */
    private $responses;

    public function __construct(GetHandler $get)
    {
        $this->get = $get;
        $this->responses = new ResponseBuilder();
    }

    /**
     * @param GetListRequest $request
     * @return GetListResponse
     */
    public function handle($request)
    {
        $refs = $request->baseRef;
        if ($refs === null) {
            $refs = [];
        } elseif (!is_array($refs)) {
            $refs = [$refs];
        }
        $reads = [];
        foreach ($refs as $ref) {
            try {
                $reads[] = $this->get->read($ref);
            } catch (RestFault $e) {
                if (!$reads) {
                    throw $e;
                }
                $reads[] = $this->responses->readFault($e);
            } catch (NotSupportedOnRestException $e) {
                $reads[] = $this->responses->readFault($e);
            }
        }
        $response = new GetListResponse();
        $response->readResponseList = $this->responses->readList($reads);
        return $response;
    }
}
