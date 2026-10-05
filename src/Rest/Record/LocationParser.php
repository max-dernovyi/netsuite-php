<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Record;

use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;
use NetSuite\Rest\Http\Response;

/**
 * Reads the internal id from the `Location` header of a write response (`…/record/v1/{type}/{id}`).
 */
final class LocationParser
{
    const PATTERN = '#/record/v1/[^/]+/(-?\d+)/?$#';

    /**
     * @throws RestError when the header is missing or does not end in a record id
     */
    public function parse(Response $response): string
    {
        $location = trim((string) $response->getHeader('Location'));
        if ($location === '') {
            throw $this->error($response, 'The response has no Location header');
        }
        $id = $this->idFromUrl($location);
        if ($id === null) {
            throw $this->error($response, 'Cannot read the record id from the Location header: '.$location);
        }
        return $id;
    }

    public function idFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match(self::PATTERN, $path, $match)) {
            return null;
        }
        return $match[1];
    }

    private function error(Response $response, string $message): RestError
    {
        return new RestError(
            $response->getStatusCode(),
            'Unreadable write response',
            [new RestErrorDetail($message, 'UNEXPECTED_ERROR')]
        );
    }
}
