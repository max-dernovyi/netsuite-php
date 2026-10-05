<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\UpsertRequest;
use NetSuite\Classes\UpsertResponse;
use NetSuite\Classes\WriteResponse;

/**
 * `upsert`: PUT by external id; a record without `externalId` fails without a request.
 */
final class UpsertHandler extends AbstractWriteHandler
{
    /**
     * @param UpsertRequest $request
     * @return UpsertResponse
     */
    public function handle($request)
    {
        $response = new UpsertResponse();
        $response->writeResponse = $this->write($request->record);
        return $response;
    }

    protected function writeItem($record): WriteResponse
    {
        $type = $this->refs->recordType($record, 'upsert');
        $externalId = RecordRefs::property($record, 'externalId');
        if ($externalId === null) {
            throw RecordRefs::invalid('upsert needs the record externalId', StatusDetailCodeType::INVALID_KEY_OR_REF);
        }
        $id = $this->records->upsert($type, $externalId, $this->serialize($record)->body());
        return $this->success($record, $type, $id);
    }
}
