<?php

namespace tests\Netsuite\Rest\Exception;

use NetSuite\Classes\ExceededConcurrentRequestLimitFault;
use NetSuite\Classes\FaultCodeType;
use NetSuite\Classes\InvalidCredentialsFault;
use NetSuite\Classes\UnexpectedErrorFault;
use NetSuite\Rest\Exception\RestFault;
use PHPUnit\Framework\TestCase;

class RestFaultTest extends TestCase
{
    public function testCaughtAsSoapFault()
    {
        try {
            throw RestFault::invalidCredentials('Invalid login attempt.', 401);
        } catch (\SoapFault $e) {
            $this->assertInstanceOf(RestFault::class, $e);
            $this->assertSame('Invalid login attempt.', $e->getMessage());
            $this->assertSame('Invalid login attempt.', $e->faultstring);
            $this->assertSame(RestFault::FAULT_CODE, $e->faultcode);
            return;
        }
        $this->fail('RestFault was not caught as SoapFault');
    }

    public function testInvalidCredentialsDetail()
    {
        $e = RestFault::invalidCredentials('Invalid login attempt.', 401);

        $fault = $e->detail->invalidCredentialsFault;
        $this->assertInstanceOf(InvalidCredentialsFault::class, $fault);
        $this->assertSame(FaultCodeType::INVALID_LOGIN_CREDENTIALS, $fault->code);
        $this->assertSame('Invalid login attempt.', $fault->message);
        $this->assertSame($fault, $e->getFault());
        $this->assertSame(401, $e->getHttpStatus());
    }

    public function testExceededConcurrentRequestLimitDetail()
    {
        $e = RestFault::exceededConcurrentRequestLimit('Too many requests', 429);

        $fault = $e->detail->exceededConcurrentRequestLimitFault;
        $this->assertInstanceOf(ExceededConcurrentRequestLimitFault::class, $fault);
        $this->assertSame(FaultCodeType::WS_CONCUR_SESSION_DISALLWD, $fault->code);
    }

    public function testUnexpectedErrorDetail()
    {
        $e = RestFault::unexpectedError('cURL error 7');

        $fault = $e->detail->unexpectedErrorFault;
        $this->assertInstanceOf(UnexpectedErrorFault::class, $fault);
        $this->assertSame(FaultCodeType::UNEXPECTED_ERROR, $fault->code);
        $this->assertNull($e->getHttpStatus());
    }

    public function testExplicitCode()
    {
        $e = new RestFault(RestFault::INVALID_CREDENTIALS, 'Role required', FaultCodeType::ROLE_REQUIRED);

        $this->assertSame(FaultCodeType::ROLE_REQUIRED, $e->getFault()->code);
    }

    public function testUnknownFaultName()
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestFault('NoSuchFault', 'x');
    }

    public function testNonFaultClassIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestFault('Customer', 'x');
    }
}
