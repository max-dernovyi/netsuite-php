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
use NetSuite\Rest\Exception\RestFault;
use NetSuite\Rest\Http\ErrorParser;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Http\RestClient;

/**
 * Record API calls (`/record/v1/{type}/{id}`).
 *
 * An id is an internal id (`42`) or an external id reference (`eid:CUST_42`, see externalId()).
 * API errors throw RestError; auth, throttling and transport failures throw RestFault.
 */
final class RecordClient
{
    const BASE_PATH = '/record/v1/';
    const EID_PREFIX = 'eid:';
    const TYPE_PATTERN = '/^[A-Za-z][A-Za-z0-9_]*$/';
    const INTERNAL_ID_PATTERN = '/^-?\d+$/';
    const EXTERNAL_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** @var RestClient */
    private $client;
    /** @var ErrorParser */
    private $errors;
    /** @var LocationParser */
    private $locations;

    public function __construct(RestClient $client)
    {
        $this->client = $client;
        $this->errors = new ErrorParser();
        $this->locations = new LocationParser();
    }

    public static function externalId(string $externalId): string
    {
        return self::EID_PREFIX.$externalId;
    }

    /**
     * @return array the record JSON with sublists and subrecords expanded
     * @throws RestError|RestFault
     */
    public function get(string $type, string $id): array
    {
        $response = $this->expectSuccess(
            $this->client->send('GET', $this->path($type, $id), ['expandSubResources' => true])
        );
        $data = $response->json();
        if ($data === null) {
            throw new RestError(
                $response->getStatusCode(),
                'Unreadable record response',
                [new RestErrorDetail('The record response is not a JSON object', 'UNEXPECTED_ERROR')]
            );
        }
        return $data;
    }

    /**
     * @return string the internal id of the new record
     * @throws RestError|RestFault
     */
    public function create(string $type, array $body): string
    {
        $response = $this->expectSuccess($this->client->send('POST', $this->path($type), [], $body));
        return $this->locations->parse($response);
    }

    /**
     * @param string[] $replace sublists whose lines are replaced instead of merged
     * @return string|null the internal id from `Location`, null when the header is absent
     * @throws RestError|RestFault
     */
    public function update(string $type, string $id, array $body, array $replace = []): ?string
    {
        $query = $replace ? ['replace' => array_values($replace)] : [];
        $response = $this->expectSuccess($this->client->send('PATCH', $this->path($type, $id), $query, $body));
        return $response->hasHeader('Location') ? $this->locations->parse($response) : null;
    }

    /**
     * @return string the internal id of the created or updated record
     * @throws RestError|RestFault
     */
    public function upsert(string $type, string $externalId, array $body): string
    {
        $path = $this->path($type, self::externalId($externalId));
        return $this->locations->parse($this->expectSuccess($this->client->send('PUT', $path, [], $body)));
    }

    /**
     * @throws RestError|RestFault
     */
    public function delete(string $type, string $id): void
    {
        $this->expectSuccess($this->client->send('DELETE', $this->path($type, $id)));
    }

    private function expectSuccess(Response $response): Response
    {
        if (!$response->isSuccessful()) {
            throw $this->errors->parse($response);
        }
        return $response;
    }

    private function path(string $type, ?string $id = null): string
    {
        if (!preg_match(self::TYPE_PATTERN, $type)) {
            throw new \InvalidArgumentException('Invalid REST record type: "'.$type.'"');
        }
        return $id === null ? self::BASE_PATH.$type : self::BASE_PATH.$type.'/'.$this->idSegment($id);
    }

    /**
     * @throws RestError for an id NetSuite would reject, without sending the request
     */
    private function idSegment(string $id): string
    {
        if (strpos($id, self::EID_PREFIX) === 0) {
            $externalId = (string) substr($id, strlen(self::EID_PREFIX));
            if (!preg_match(self::EXTERNAL_ID_PATTERN, $externalId)) {
                throw $this->invalidId(
                    'Invalid external id "'.$externalId.'": REST accepts only letters, digits, "_" and "-"'
                );
            }
            return self::EID_PREFIX.rawurlencode($externalId);
        }
        if (!preg_match(self::INTERNAL_ID_PATTERN, $id)) {
            throw $this->invalidId('Invalid internal id "'.$id.'"');
        }
        return $id;
    }

    private function invalidId(string $message): RestError
    {
        return new RestError(400, 'Bad Request', [new RestErrorDetail($message, 'INVALID_KEY_OR_REF')]);
    }
}
