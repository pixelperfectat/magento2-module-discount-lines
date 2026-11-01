<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\QuoteLineStore;

class QuoteLineStoreTest extends TestCase
{
    private QuoteLineStore $store;

    protected function setUp(): void
    {
        $this->store = new QuoteLineStore(new Json());
    }

    private function address(): Address
    {
        $data = [];
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData'])
            ->getMock();
        $address->method('setData')->willReturnCallback(
            static function ($key, $value = null) use (&$data, $address) {
                $data[$key] = $value;

                return $address;
            }
        );
        $address->method('getData')->willReturnCallback(function ($key = "") use (&$data) { return $data[$key] ?? null; });

        return $address;
    }

    public function testRoundTripKeepsAllFields(): void
    {
        $address = $this->address();
        $lines = [
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.3, 0),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.2, 1),
        ];

        $this->store->store($address, $lines);
        $loaded = $this->store->load($address);

        $this->assertEquals($lines, $loaded);
    }

    public function testEmptyLinesClearTheStoredValue(): void
    {
        $address = $this->address();
        $this->store->store($address, [new DiscountLine('coupon', 1, 'C', 'C', 1.0, 1.0)]);

        $this->store->store($address, []);

        $this->assertNull($address->getData('pp_discount_lines'));
        $this->assertSame([], $this->store->load($address));
    }

    public function testMissingOrInvalidDataLoadsAsEmpty(): void
    {
        foreach ([null, '', 'not json', '"scalar"', '[{"foo":1}]', '[1]'] as $raw) {
            $address = $this->address();
            $address->setData('pp_discount_lines', $raw);
            $this->assertSame([], $this->store->load($address), var_export($raw, true));
        }
    }

    public function testRowWithANonStringLabelLoadsAsEmpty(): void
    {
        $address = $this->address();
        $address->setData('pp_discount_lines', '[{"kind":"coupon","label":5}]');

        $this->assertSame([], $this->store->load($address));
    }

    public function testMinimalRowGetsDefaults(): void
    {
        $address = $this->address();
        $address->setData('pp_discount_lines', '[{"kind":"gift","label":"FREEBIE"}]');

        $this->assertEquals([new DiscountLine('gift', null, null, 'FREEBIE', 0.0, 0.0, 0)], $this->store->load($address));
    }

    public function testScalarFieldsAreNormalisedToTheirTypes(): void
    {
        $address = $this->address();
        $address->setData(
            'pp_discount_lines',
            '[{"kind":"coupon","rule_id":"7","code":1234,"label":"1234","amount":"2.5","base_amount":"5","sort_order":"3"}]'
        );

        $this->assertEquals([new DiscountLine('coupon', 7, '1234', '1234', 2.5, 5.0, 3)], $this->store->load($address));
    }
}
