<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\UpdateRequest;
use NetSuite\Classes\UpdateResponse;
use NetSuite\Classes\WriteResponse;

/**
 * `update`: PATCH by internal id, else by external id; replaced sublists go to `?replace=`.
 */
final class UpdateHandler extends AbstractWriteHandler
{
    /**
     * @param UpdateRequest $request
     * @return UpdateResponse
     */
    public function handle($request)
    {
        $response = new UpdateResponse();
        $response->writeResponse = $this->write($request->record);
        return $response;
    }

    protected function writeItem($record): WriteResponse
    {
        $type = $this->refs->recordType($record, 'update');
        $id = $this->refs->id($record);
        $serialized = $this->serialize($record);
        $location = $this->records->update($type, $id, $serialized->body(), $serialized->replace());
        return $this->success($record, $type, $location ?: RecordRefs::property($record, 'internalId'));
    }
}
