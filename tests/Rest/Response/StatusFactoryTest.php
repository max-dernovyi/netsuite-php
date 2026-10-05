<?php

namespace tests\Netsuite\Rest\Response;

use NetSuite\Classes\Status;
use NetSuite\Classes\StatusDetail;
use NetSuite\Classes\StatusDetailCodeType;
use NetSuite\Classes\StatusDetailType;
use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Exception\RestErrorDetail;
use NetSuite\Rest\Response\StatusFactory;
use PHPUnit\Framework\TestCase;

class StatusFactoryTest extends TestCase
{
    /** @var StatusFactory */
    private $factory;

    protected function setUp(): void
    {
        $this->factory = new StatusFactory();
    }

    public function testSuccess()
    {
        $status = $this->factory->success();

        $this->assertInstanceOf(Status::class, $status);
        $this->assertTrue($status->isSuccess);
        $this->assertNull($status->statusDetail);
    }

    public function testKnownErrorCodeIsKept()
    {
        $status = $this->factory->fromError(new RestError(404, 'Record Not Found', [
            new RestErrorDetail('That record does not exist.', 'NONEXISTENT_ID', 'id'),
        ]));

        $this->assertFalse($status->isSuccess);
        $this->assertCount(1, $status->statusDetail);
        $detail = $status->statusDetail[0];
        $this->assertInstanceOf(StatusDetail::class, $detail);
        $this->assertSame(StatusDetailCodeType::NONEXISTENT_ID, $detail->code);
        $this->assertSame('That record does not exist.', $detail->message);
        $this->assertSame(StatusDetailType::ERROR, $detail->type);
    }

    public function testOneStatusDetailPerErrorDetail()
    {
        $status = $this->factory->fromError(new RestError(400, 'Bad Request', [
            new RestErrorDetail('Invalid content.', 'INVALID_CONTENT'),
            new RestErrorDetail('Please enter a value for Name.', 'USER_ERROR'),
        ]));

        $this->assertSame(
            [StatusDetailCodeType::INVALID_CONTENT, StatusDetailCodeType::USER_ERROR],
            array_map(function (StatusDetail $d) {
                return $d->code;
            }, $status->statusDetail)
        );
        $this->assertSame('Please enter a value for Name.', $status->statusDetail[1]->message);
    }

    /**
     * @dataProvider genericCodes
     */
    public function testUnknownOrMissingCodeFallsBackByHttpStatus($errorCode, int $httpStatus, string $expected)
    {
        $status = $this->factory->fromError(new RestError($httpStatus, 'Error', [
            new RestErrorDetail('Something went wrong.', $errorCode),
        ]));

        $this->assertSame($expected, $status->statusDetail[0]->code);
    }

    public function genericCodes(): array
    {
        return [
            'unknown 4xx'  => ['SOME_REST_ONLY_CODE', 400, StatusDetailCodeType::USER_ERROR],
            'missing 4xx'  => [null, 404, StatusDetailCodeType::USER_ERROR],
            '403'          => [null, 403, StatusDetailCodeType::USER_ERROR],
            'unknown 5xx'  => ['SOME_REST_ONLY_CODE', 500, StatusDetailCodeType::UNEXPECTED_ERROR],
            'missing 5xx'  => [null, 503, StatusDetailCodeType::UNEXPECTED_ERROR],
            'lower case'   => ['nonexistent_id', 404, StatusDetailCodeType::USER_ERROR],
        ];
    }

    public function testEmptyDetailUsesTitle()
    {
        $status = $this->factory->fromError(new RestError(400, 'Bad Request', [new RestErrorDetail('')]));

        $this->assertSame('Bad Request', $status->statusDetail[0]->message);
    }
}
