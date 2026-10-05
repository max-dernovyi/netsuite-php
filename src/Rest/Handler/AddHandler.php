<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\AddRequest;
use NetSuite\Classes\AddResponse;
use NetSuite\Classes\WriteResponse;

/**
 * `add`: POST the record; the new internal id comes from `Location`.
 */
final class AddHandler extends AbstractWriteHandler
{
    /**
     * @param AddRequest $request
     * @return AddResponse
     */
    public function handle($request)
    {
        $response = new AddResponse();
        $response->writeResponse = $this->write($request->record);
        return $response;
    }

    protected function writeItem($record): WriteResponse
    {
        $type = $this->refs->recordType($record, 'add');
        $id = $this->records->create($type, $this->serialize($record)->body());
        return $this->success($record, $type, $id);
    }
}
