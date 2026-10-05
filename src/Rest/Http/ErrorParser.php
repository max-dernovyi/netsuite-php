<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Http;

use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;

/**
 * Turns a NetSuite REST error response into a RestError.
 */
final class ErrorParser
{
    const MAX_BODY_LENGTH = 500;

    public function parse(Response $response): RestError
    {
        $status = $response->getStatusCode();
        $data = $response->json();
        if ($data === null || !$this->isErrorBody($data)) {
            return $this->generic($status, $response->getBody());
        }

        $details = [];
        $items = isset($data['o:errorDetails']) && is_array($data['o:errorDetails']) ? $data['o:errorDetails'] : [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $details[] = new RestErrorDetail(
                $this->stringOrNull($item, 'detail') ?? '',
                $this->stringOrNull($item, 'o:errorCode'),
                $this->stringOrNull($item, 'o:errorPath')
            );
        }

        $title = $this->stringOrNull($data, 'title') ?? 'HTTP ' . $status;
        if ($details === []) {
            $details[] = new RestErrorDetail($title);
        }

        return new RestError($status, $title, $details, $this->stringOrNull($data, 'type'));
    }

    private function isErrorBody(array $data): bool
    {
        return isset($data['o:errorDetails']) || isset($data['title']) || isset($data['type']);
    }

    private function generic(int $status, string $body): RestError
    {
        $body = trim($body);
        if ($body === '') {
            $text = 'Empty response body';
        } elseif (strlen($body) > self::MAX_BODY_LENGTH) {
            $text = substr($body, 0, self::MAX_BODY_LENGTH) . '...';
        } else {
            $text = $body;
        }
        return new RestError($status, 'HTTP ' . $status, [new RestErrorDetail($text)]);
    }

    private function stringOrNull(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : null;
    }
}
