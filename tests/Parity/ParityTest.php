<?php

namespace tests\Netsuite\Parity;

use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Customer;
use NetSuite\Classes\DeleteRequest;
use NetSuite\Classes\GetListRequest;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\UpdateRequest;
use NetSuite\Classes\UpsertRequest;
use NetSuite\NetSuiteService;
use NetSuite\Rest\Config\RestConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sends the same requests over SOAP and REST to one account and compares the normalised responses.
 *
 * @group parity
 */
class ParityTest extends TestCase
{
    const MISSING_ID = '999999999';

    /** @var ParityEnv */
    private $env;

    /** @var ResponseNormalizer */
    private $normalizer;

    protected function setUp(): void
    {
        $env = ParityEnv::fromEnvironment();
        if ($env === null) {
            $this->markTestSkipped('Parity tests need NETSUITE_PARITY_ACCOUNT and the TBA keys');
        }
        $this->env = $env;
        $this->normalizer = new ResponseNormalizer();
    }

    public function testGetCustomer(): void
    {
        $id = $this->requireValue('NETSUITE_PARITY_CUSTOMER_ID');
        $this->assertParity(function (NetSuiteService $service) use ($id) {
            return $this->normalizer->normalize($service->get($this->getRequest(RecordType::customer, $id)));
        });
    }

    public function testGetSalesOrder(): void
    {
        $id = $this->requireValue('NETSUITE_PARITY_SALES_ORDER_ID');
        $this->assertParity(function (NetSuiteService $service) use ($id) {
            return $this->normalizer->normalize($service->get($this->getRequest(RecordType::salesOrder, $id)));
        });
    }

    public function testAddUpdateDeleteCustomer(): void
    {
        $this->assertParity(function (NetSuiteService $service, string $transport) {
            $externalId = $this->uniqueExternalId($transport);
            $placeholders = [$externalId => '{externalId}'];

            $add = new AddRequest();
            $add->record = $this->customer($externalId);
            $steps = ['add' => $service->add($add)];

            $id = $steps['add']->writeResponse->baseRef->internalId ?? null;
            if ($id !== null) {
                $placeholders[$id] = '{internalId}';
                try {
                    $update = new UpdateRequest();
                    $update->record = new Customer();
                    $update->record->internalId = $id;
                    $update->record->comments = 'parity update';
                    $steps['update'] = $service->update($update);
                } finally {
                    $steps['delete'] = $service->delete($this->deleteRequest($this->ref(RecordType::customer, $id)));
                }
            }

            return $this->normalizer->normalize($steps, $placeholders);
        });
    }

    public function testUpsertByExternalId(): void
    {
        $this->assertParity(function (NetSuiteService $service, string $transport) {
            $externalId = $this->uniqueExternalId($transport);
            $placeholders = [$externalId => '{externalId}'];

            try {
                $create = new UpsertRequest();
                $create->record = $this->customer($externalId);
                $steps = ['create' => $service->upsert($create)];

                $id = $steps['create']->writeResponse->baseRef->internalId ?? null;
                if ($id !== null) {
                    $placeholders[$id] = '{internalId}';
                }

                $update = new UpsertRequest();
                $update->record = $this->customer($externalId);
                $update->record->comments = 'parity upsert';
                $steps['update'] = $service->upsert($update);
            } finally {
                $ref = new RecordRef();
                $ref->type = RecordType::customer;
                $ref->externalId = $externalId;
                $service->delete($this->deleteRequest($ref));
            }

            return $this->normalizer->normalize($steps, $placeholders);
        });
    }

    public function testGetListWithMissingId(): void
    {
        $id = $this->requireValue('NETSUITE_PARITY_CUSTOMER_ID');
        $this->assertParity(function (NetSuiteService $service) use ($id) {
            $request = new GetListRequest();
            $request->baseRef = [
                $this->ref(RecordType::customer, $id),
                $this->ref(RecordType::customer, self::MISSING_ID),
            ];
            return $this->normalizer->normalize($service->getList($request));
        });
    }

    /**
     * @param callable $scenario fn(NetSuiteService $service, string $transport): array, the normalised responses
     */
    private function assertParity(callable $scenario): void
    {
        $soap = $scenario($this->service($this->env->soapConfig()), RestConfig::TRANSPORT_SOAP);
        $rest = $scenario($this->service($this->env->restConfig()), RestConfig::TRANSPORT_REST);

        $this->assertSame($soap, $rest, 'REST response differs from SOAP');
    }

    private function service(array $config): NetSuiteService
    {
        return new NetSuiteService($config, [], null, new NullLogger());
    }

    private function requireValue(string $name): string
    {
        $value = $this->env->value($name);
        if ($value === null) {
            $this->markTestSkipped('Set '.$name.' to run this scenario');
        }
        return $value;
    }

    private function uniqueExternalId(string $transport): string
    {
        return 'parity_'.bin2hex(random_bytes(4)).'_'.$transport;
    }

    private function customer(string $externalId): Customer
    {
        $customer = new Customer();
        $customer->externalId = $externalId;
        $customer->entityId = $externalId;
        $customer->companyName = $externalId;
        $subsidiary = $this->env->value('NETSUITE_PARITY_SUBSIDIARY_ID');
        if ($subsidiary !== null) {
            $customer->subsidiary = $this->ref(RecordType::subsidiary, $subsidiary);
        }
        return $customer;
    }

    private function ref(string $type, string $internalId): RecordRef
    {
        $ref = new RecordRef();
        $ref->type = $type;
        $ref->internalId = $internalId;
        return $ref;
    }

    private function getRequest(string $type, string $internalId): GetRequest
    {
        $request = new GetRequest();
        $request->baseRef = $this->ref($type, $internalId);
        return $request;
    }

    private function deleteRequest(RecordRef $ref): DeleteRequest
    {
        $request = new DeleteRequest();
        $request->baseRef = $ref;
        return $request;
    }
}
