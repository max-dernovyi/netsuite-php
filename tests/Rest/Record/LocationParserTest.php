<?php

namespace tests\Netsuite\Rest\Record;

use NetSuite\Rest\Exception\RestError;
use NetSuite\Rest\Http\Response;
use NetSuite\Rest\Record\LocationParser;
use PHPUnit\Framework\TestCase;

class LocationParserTest extends TestCase
{
    /**
     * @dataProvider readableLocations
     */
    public function testReadsId(string $location, string $id)
    {
        $this->assertSame($id, (new LocationParser())->parse(new Response(204, ['location' => $location])));
    }

    public function readableLocations(): array
    {
        $base = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/record/v1';
        return [
            'absolute'        => [$base.'/customer/1234', '1234'],
            'custom record'   => [$base.'/customrecord_widget/7', '7'],
            'trailing slash'  => [$base.'/salesOrder/88/', '88'],
            'query string'    => [$base.'/customer/5?foo=bar', '5'],
            'negative id'     => [$base.'/employee/-5', '-5'],
            'relative'        => ['/services/rest/record/v1/customer/42', '42'],
            'surrounding ws'  => ['  '.$base.'/customer/3 ', '3'],
        ];
    }

    /**
     * @dataProvider unreadableLocations
     */
    public function testUnreadableLocationIsRestError(array $headers)
    {
        try {
            (new LocationParser())->parse(new Response(204, $headers));
        } catch (RestError $error) {
            $this->assertSame(204, $error->getHttpStatus());
            $this->assertSame('UNEXPECTED_ERROR', $error->getDetails()[0]->getErrorCode());
            return;
        }
        $this->fail('RestError was not thrown');
    }

    public function unreadableLocations(): array
    {
        $base = 'https://123456-sb1.suitetalk.api.netsuite.com/services/rest/record/v1';
        return [
            'missing'         => [[]],
            'empty'           => [['Location' => '']],
            'no id'           => [['Location' => $base.'/customer']],
            'external id'     => [['Location' => $base.'/customer/eid:C1']],
            'not a record'    => [['Location' => 'https://example.com/other/1']],
            'not a url'       => [['Location' => 'garbage']],
        ];
    }
}
