<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Exception;

/**
 * A NetSuite REST API error; handlers turn it into a SOAP-style status.
 */
final class RestError extends \RuntimeException
{
    /** @var int */
    private $httpStatus;
    /** @var string */
    private $title;
    /** @var string|null */
    private $type;
    /** @var RestErrorDetail[] */
    private $details;

    /**
     * @param RestErrorDetail[] $details
     */
    public function __construct(int $httpStatus, string $title, array $details, ?string $type = null)
    {
        $this->httpStatus = $httpStatus;
        $this->title = $title;
        $this->type = $type;
        $this->details = array_values($details);

        $messages = array_filter(array_map(function (RestErrorDetail $detail) {
            return $detail->getDetail();
        }, $this->details), 'strlen');
        parent::__construct($messages ? implode('; ', $messages) : $title, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /**
     * @return RestErrorDetail[]
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
