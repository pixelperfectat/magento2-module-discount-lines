<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\QuoteDiscountLines;

class QuoteDiscountLinesTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private DiscountLineResolverInterface&MockObject $resolver;
    private QuoteDiscountLines $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->resolver = $this->createMock(DiscountLineResolverInterface::class);
        $this->service = new QuoteDiscountLines($this->config, $this->resolver);
    }

    private function quote(float $discount): Quote
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['getDiscountAmount', 'getBaseDiscountAmount'])
            ->getMock();
        $address->method('getDiscountAmount')->willReturn($discount);
        $address->method('getBaseDiscountAmount')->willReturn($discount);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'isVirtual', 'getShippingAddress', 'getBillingAddress'])
            ->getMock();
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);

        return $quote;
    }

    public function testLinesSumToAddressDiscount(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('resolve')->willReturn([
            new DiscountLine('coupon', 1, 'LAB-PERCENT-15', 'LAB-PERCENT-15', 3.33, 3.33),
            new DiscountLine('automatic', 2, null, 'Autumn sale', 1.11, 1.11),
            new DiscountLine('automatic', 3, null, 'Other', 1.11, 1.11),
        ]);

        $lines = $this->service->getLines($this->quote(-5.56));

        $sum = array_sum(array_map(fn ($line) => $line->getAmount(), $lines));
        $this->assertSame(5.56, round($sum, 2));
        $this->assertSame(3.34, $lines[0]->getAmount(), 'largest line absorbs the rounding difference');
        $this->assertSame([0, 1, 2], array_map(fn ($line) => $line->getSortOrder(), $lines));
    }

    public function testFreeShippingLineIsNotReconciled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('resolve')->willReturn([
            new DiscountLine('coupon', 1, 'X', 'X', 2.0, 2.0),
            new DiscountLine('free_shipping', 9, null, 'Free shipping', 0.0, 0.0),
        ]);

        $lines = $this->service->getLines($this->quote(-2.0));

        $this->assertSame(2.0, $lines[0]->getAmount());
        $this->assertSame(0.0, $lines[1]->getAmount());
    }

    public function testDisabledReturnsEmpty(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->resolver->expects($this->never())->method('resolve');

        $this->assertSame([], $this->service->getLines($this->quote(-5.0)));
    }

    public function testNoLinesReturnsEmpty(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->resolver->method('resolve')->willReturn([]);

        $this->assertSame([], $this->service->getLines($this->quote(0.0)));
    }
}
