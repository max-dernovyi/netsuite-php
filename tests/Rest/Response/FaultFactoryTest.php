<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Rest\Response;

use NetSuite\Classes\ExceededConcurrentRequestLimitFault;
use NetSuite\Classes\InvalidCredentialsFault;
use NetSuite\Classes\UnexpectedErrorFault;
use NetSuite\Rest\Exception\TransportException;
use NetSuite\Rest\Response\FaultFactory;
use PHPUnit\Framework\TestCase;

class FaultFactoryTest extends TestCase
{
    /**
     * @dataProvider faults
     */
    public function testHttpStatusToFault(int $httpStatus, string $faultClass, string $faultName)
    {
        $fault = (new FaultFactory())->forHttpStatus($httpStatus, 'Failed.');

        $this->assertInstanceOf(\SoapFault::class, $fault);
        $this->assertInstanceOf($faultClass, $fault->getFault());
        $this->assertSame($fault->getFault(), $fault->detail->{lcfirst($faultName)});
        $this->assertSame('Failed.', $fault->getMessage());
        $this->assertSame($httpStatus, $fault->getHttpStatus());
    }

    public function faults(): array
    {
        return [
            '401' => [401, InvalidCredentialsFault::class, 'InvalidCredentialsFault'],
            '429' => [429, ExceededConcurrentRequestLimitFault::class, 'ExceededConcurrentRequestLimitFault'],
            '500' => [500, UnexpectedErrorFault::class, 'UnexpectedErrorFault'],
            '503' => [503, UnexpectedErrorFault::class, 'UnexpectedErrorFault'],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testOtherHttpStatusesStayStatuses(int $httpStatus)
    {
        $this->assertNull((new FaultFactory())->forHttpStatus($httpStatus, 'Failed.'));
    }

    public function statuses(): array
    {
        return ['200' => [200], '400' => [400], '403' => [403], '404' => [404], '409' => [409]];
    }

    public function testFromTransport()
    {
        $factory = new FaultFactory();
        $error = new TransportException('Connection refused');

        $fault = $factory->fromTransport($error);
        $this->assertInstanceOf(UnexpectedErrorFault::class, $fault->getFault());
        $this->assertSame('Connection refused', $fault->getMessage());
        $this->assertNull($fault->getHttpStatus());

        $this->assertSame('GET x failed: Connection refused', $factory->fromTransport($error, 'GET x failed: Connection refused')->getMessage());
    }
}
