<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace tests\Netsuite\Parity;

use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\ListOrRecordRef;
use NetSuite\Classes\ReadResponse;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SelectCustomFieldRef;
use NetSuite\Classes\Status;
use NetSuite\Classes\StatusDetail;
use NetSuite\Classes\StringCustomFieldRef;
use NetSuite\Classes\WriteResponse;
use PHPUnit\Framework\TestCase;

class ResponseNormalizerTest extends TestCase
{
    /** @var ResponseNormalizer */
    private $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ResponseNormalizer();
    }

    public function testObjectsBecomeSortedArraysWithoutNulls(): void
    {
        $status = new Status();
        $status->isSuccess = false;
        $detail = new StatusDetail();
        $detail->type = 'ERROR';
        $detail->code = 'INVALID_KEY_OR_REF';
        $status->statusDetail = [$detail];

        $this->assertSame([
            '@class' => 'Status',
            'isSuccess' => false,
            'statusDetail' => [
                ['@class' => 'StatusDetail', 'code' => 'INVALID_KEY_OR_REF', 'type' => 'ERROR'],
            ],
        ], $this->normalizer->normalize($status));
    }

    public function testEmptyListsAreDropped(): void
    {
        $status = new Status();
        $status->isSuccess = true;
        $status->statusDetail = [];

        $this->assertSame(['@class' => 'Status', 'isSuccess' => true], $this->normalizer->normalize($status));
    }

    public function testVolatileDatesAreDropped(): void
    {
        $customer = new Customer();
        $customer->companyName = 'Acme';
        $customer->dateCreated = '2026-10-05T10:00:00.000-07:00';
        $customer->lastModifiedDate = '2026-10-05T10:00:01.000-07:00';

        $this->assertSame(['@class' => 'Customer', 'companyName' => 'Acme'], $this->normalizer->normalize($customer));
    }

    public function testVolatileFieldsAreConfigurable(): void
    {
        $customer = new Customer();
        $customer->companyName = 'Acme';
        $customer->lastModifiedDate = '2026-10-05T10:00:01.000-07:00';

        $normalized = (new ResponseNormalizer(['companyName']))->normalize($customer);

        $this->assertSame(
            ['@class' => 'Customer', 'lastModifiedDate' => '2026-10-05T10:00:01.000-07:00'],
            $normalized
        );
    }

    public function testRecordRefTypeIsDropped(): void
    {
        $soap = $this->recordRef('647', 'customer');
        $rest = $this->recordRef('647', null);

        $this->assertSame(['@class' => 'RecordRef', 'internalId' => '647'], $this->normalizer->normalize($soap));
        $this->assertSame($this->normalizer->normalize($soap), $this->normalizer->normalize($rest));
    }

    public function testCustomFieldInternalIdIsDroppedButNestedRefsKeepIt(): void
    {
        $value = new ListOrRecordRef();
        $value->internalId = '3';
        $field = new SelectCustomFieldRef();
        $field->internalId = '120';
        $field->scriptId = 'custentity_tier';
        $field->value = $value;

        $this->assertSame([
            '@class' => 'SelectCustomFieldRef',
            'scriptId' => 'custentity_tier',
            'value' => ['@class' => 'ListOrRecordRef', 'internalId' => '3'],
        ], $this->normalizer->normalize($field));
    }

    public function testCustomFieldOrderIsIgnored(): void
    {
        $soap = $this->customFields(['custentity_b', 'custentity_a']);
        $rest = $this->customFields(['custentity_a', 'custentity_b']);

        $normalized = $this->normalizer->normalize($soap);

        $this->assertSame($normalized, $this->normalizer->normalize($rest));
        $this->assertSame('custentity_a', $normalized['customField'][0]['scriptId']);
    }

    public function testPlaceholdersReplaceExactValuesOnly(): void
    {
        $response = new WriteResponse();
        $response->baseRef = $this->recordRef('901', 'customer');
        $response->baseRef->externalId = 'parity_ab12_rest';
        $response->baseRef->name = '9010';

        $normalized = $this->normalizer->normalize($response, [
            '901' => '{internalId}',
            'parity_ab12_rest' => '{externalId}',
        ]);

        $this->assertSame([
            '@class' => 'RecordRef',
            'externalId' => '{externalId}',
            'internalId' => '{internalId}',
            'name' => '9010',
        ], $normalized['baseRef']);
    }

    public function testAssociativeArraysKeepTheirKeys(): void
    {
        $read = new ReadResponse();
        $read->status = new Status();
        $read->status->isSuccess = true;

        $this->assertSame(
            ['get' => ['@class' => 'ReadResponse', 'status' => ['@class' => 'Status', 'isSuccess' => true]]],
            $this->normalizer->normalize(['get' => $read, 'skipped' => null])
        );
    }

    public function testDifferentValuesStillDiffer(): void
    {
        $this->assertNotSame(
            $this->normalizer->normalize($this->recordRef('647', 'customer')),
            $this->normalizer->normalize($this->recordRef('648', 'customer'))
        );
    }

    private function recordRef(string $internalId, ?string $type): RecordRef
    {
        $ref = new RecordRef();
        $ref->internalId = $internalId;
        $ref->type = $type;
        return $ref;
    }

    /**
     * @param string[] $scriptIds
     */
    private function customFields(array $scriptIds): CustomFieldList
    {
        $list = new CustomFieldList();
        $list->customField = [];
        foreach ($scriptIds as $i => $scriptId) {
            $field = new StringCustomFieldRef();
            $field->internalId = (string) (100 + $i);
            $field->scriptId = $scriptId;
            $field->value = 'v';
            $list->customField[] = $field;
        }
        return $list;
    }
}
