<?php

namespace tests\Netsuite\Rest\Http;

use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Http\ErrorParser;
use NetSuite\Rest\Http\Response;
use PHPUnit\Framework\TestCase;

class ErrorParserTest extends TestCase
{
    private function parse(int $status, string $body): RestError
    {
        return (new ErrorParser())->parse(new Response($status, ['Content-Type' => 'application/vnd.oracle.resource+json'], $body));
    }

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/errors/' . $name . '.json');
    }

    public function testSingleDetail()
    {
        $error = $this->parse(404, $this->fixture('not-found'));

        $this->assertSame(404, $error->getHttpStatus());
        $this->assertSame(404, $error->getCode());
        $this->assertSame('Not Found', $error->getTitle());
        $this->assertSame('https://www.rfc-editor.org/rfc/rfc9110.html#section-15.5.5', $error->getType());
        $this->assertCount(1, $error->getDetails());
        $detail = $error->getDetails()[0];
        $this->assertSame('NONEXISTENT_ID', $detail->getErrorCode());
        $this->assertSame('The record instance does not exist. Provide a valid record instance ID.', $detail->getDetail());
        $this->assertNull($detail->getErrorPath());
        $this->assertSame($detail->getDetail(), $error->getMessage());
    }

    public function testSeveralDetails()
    {
        $error = $this->parse(400, $this->fixture('invalid-content'));

        $details = $error->getDetails();
        $this->assertCount(2, $details);
        $this->assertSame('INVALID_CONTENT', $details[0]->getErrorCode());
        $this->assertSame('subsidiary', $details[0]->getErrorPath());
        $this->assertSame('USER_ERROR', $details[1]->getErrorCode());
        $this->assertSame('companyName', $details[1]->getErrorPath());
        $this->assertSame($details[0]->getDetail() . '; ' . $details[1]->getDetail(), $error->getMessage());
    }

    public function testUnauthorized()
    {
        $error = $this->parse(401, $this->fixture('unauthorized'));

        $this->assertSame('INVALID_LOGIN', $error->getDetails()[0]->getErrorCode());
        $this->assertStringStartsWith('Invalid login attempt.', $error->getMessage());
    }

    public function testStatusComesFromResponseNotBody()
    {
        $error = $this->parse(400, $this->fixture('not-found'));

        $this->assertSame(400, $error->getHttpStatus());
    }

    public function testErrorBodyWithoutDetailsUsesTitle()
    {
        $error = $this->parse(403, '{"type":"x","title":"Forbidden","status":403}');

        $this->assertCount(1, $error->getDetails());
        $this->assertSame('Forbidden', $error->getDetails()[0]->getDetail());
        $this->assertNull($error->getDetails()[0]->getErrorCode());
        $this->assertSame('Forbidden', $error->getMessage());
    }

    public function testMalformedDetailEntriesAreSkipped()
    {
        $error = $this->parse(400, '{"title":"Bad Request","o:errorDetails":["oops",{"o:errorCode":"USER_ERROR"}]}');

        $this->assertCount(1, $error->getDetails());
        $this->assertSame('', $error->getDetails()[0]->getDetail());
        $this->assertSame('USER_ERROR', $error->getDetails()[0]->getErrorCode());
        $this->assertSame('Bad Request', $error->getMessage());
    }

    public function testNonJsonBody()
    {
        $error = $this->parse(502, '<html><body>Bad Gateway</body></html>');

        $this->assertSame(502, $error->getHttpStatus());
        $this->assertSame('HTTP 502', $error->getTitle());
        $this->assertNull($error->getType());
        $this->assertCount(1, $error->getDetails());
        $this->assertSame('<html><body>Bad Gateway</body></html>', $error->getDetails()[0]->getDetail());
        $this->assertNull($error->getDetails()[0]->getErrorCode());
    }

    public function testNonJsonBodyIsTruncated()
    {
        $error = $this->parse(500, str_repeat('x', 2000));

        $detail = $error->getDetails()[0]->getDetail();
        $this->assertSame(ErrorParser::MAX_BODY_LENGTH + 3, strlen($detail));
        $this->assertStringEndsWith('...', $detail);
    }

    public function testEmptyBody()
    {
        $error = $this->parse(503, '');

        $this->assertSame('Empty response body', $error->getMessage());
    }

    public function testJsonWithoutErrorShapeIsGeneric()
    {
        $error = $this->parse(500, '{"foo":"bar"}');

        $this->assertSame('HTTP 500', $error->getTitle());
        $this->assertSame('{"foo":"bar"}', $error->getMessage());
    }
}
