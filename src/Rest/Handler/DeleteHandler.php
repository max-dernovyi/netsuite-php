<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\DeleteRequest;
use NetSuite\Classes\DeleteResponse;
use NetSuite\Classes\WriteResponse;
use NetSuite\Rest\Record\RecordClient;
use NetSuite\Rest\Record\RecordTypeResolver;
use NetSuite\Rest\Response\ResponseBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * `delete`: DELETE by internal id, else by external id. REST has no field for `deletionReason`.
 */
final class DeleteHandler extends AbstractWriteHandler
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        RecordClient $records,
        ?LoggerInterface $logger = null,
        ?RecordTypeResolver $types = null,
        ?ResponseBuilder $responses = null
    ) {
        parent::__construct($records, $types, null, $responses);
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * @param DeleteRequest $request
     * @return DeleteResponse
     */
    public function handle($request)
    {
        $this->ignoreDeletionReason($request, 'delete');
        $response = new DeleteResponse();
        $response->writeResponse = $this->write($request->baseRef);
        return $response;
    }

    /**
     * @param object $request a `DeleteRequest` or `DeleteListRequest`
     */
    public function ignoreDeletionReason($request, string $operation): void
    {
        if (isset($request->deletionReason)) {
            $this->logger->debug(
                sprintf('NetSuite REST: operation "%s" ignores deletionReason', $operation),
                ['operation' => $operation]
            );
        }
    }

    protected function writeItem($ref): WriteResponse
    {
        $type = $this->refs->refType($ref, 'delete');
        $this->records->delete($type, $this->refs->id($ref));
        return $this->success($ref, $type, RecordRefs::property($ref, 'internalId'));
    }
}
