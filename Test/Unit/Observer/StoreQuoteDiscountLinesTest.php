<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteLineStoreInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Observer\StoreQuoteDiscountLines;
use Psr\Log\LoggerInterface;

class StoreQuoteDiscountLinesTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private QuoteDiscountLinesInterface&MockObject $quoteLines;
    private QuoteLineStoreInterface&MockObject $store;
    private LoggerInterface&MockObject $logger;
    private StoreQuoteDiscountLines $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->quoteLines = $this->createMock(QuoteDiscountLinesInterface::class);
        $this->store = $this->createMock(QuoteLineStoreInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new StoreQuoteDiscountLines($this->config, $this->quoteLines, $this->store, $this->logger);
    }

    private function quote(bool $virtual): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getStoreId'])
            ->getMock();
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }

    private function address(string $type): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(["getAddressType"])
            ->getMock();
        $address->method('getAddressType')->willReturn($type);

        return $address;
    }

    private function event(Quote $quote, Address $address): Observer
    {
        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($address);
        $assignment = $this->createMock(ShippingAssignmentInterface::class);
        $assignment->method('getShipping')->willReturn($shipping);

        return new Observer(['event' => new Event(['quote' => $quote, 'shipping_assignment' => $assignment])]);
    }

    public function testStoresLinesOnShippingAddressWhenCollecting(): void
    {
        $quote = $this->quote(false);
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $lines = [new DiscountLine('coupon', 1, 'CODE', 'CODE', 2.0, 2.0)];
        $this->quoteLines->method('getLinesForAddress')->with($address)->willReturn($lines);
        $this->store->expects($this->once())->method('store')->with($address, $lines);

        $this->observer->execute($this->event($quote, $address));
    }

    public function testClearsStoredLinesWhenThereAreNone(): void
    {
        $quote = $this->quote(false);
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $this->quoteLines->method('getLinesForAddress')->willReturn([]);
        $this->store->expects($this->once())->method('store')->with($address, []);

        $this->observer->execute($this->event($quote, $address));
    }

    public function testBillingAddressCarriesTheLinesOfVirtualQuotes(): void
    {
        $quote = $this->quote(true);
        $this->quoteLines->method('getLinesForAddress')->willReturn([]);
        $this->store->expects($this->once())->method('store');

        $this->observer->execute($this->event($quote, $this->address(Address::ADDRESS_TYPE_SHIPPING)));
        $this->observer->execute($this->event($quote, $this->address(Address::ADDRESS_TYPE_BILLING)));
    }

    public function testBillingAddressOfPhysicalQuoteIsIgnored(): void
    {
        $this->store->expects($this->never())->method('store');

        $this->observer->execute($this->event($this->quote(false), $this->address(Address::ADDRESS_TYPE_BILLING)));
    }

    public function testUsesEventAddressNotQuoteShippingAddress(): void
    {
        $other = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getStoreId', 'getShippingAddress'])
            ->getMock();
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getShippingAddress')->willReturn($other);
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $this->quoteLines->expects($this->once())->method('getLinesForAddress')->with($this->identicalTo($address))
            ->willReturn([]);
        $this->store->expects($this->once())->method('store')->with($this->identicalTo($address), []);

        $this->observer->execute($this->event($quote, $address));
    }

    public function testDisabledStoreClearsStoredLines(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $observer = new StoreQuoteDiscountLines($config, $this->quoteLines, $this->store, $this->logger);
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $this->quoteLines->expects($this->never())->method('getLinesForAddress');
        $this->store->expects($this->once())->method('store')->with($address, []);

        $observer->execute($this->event($this->quote(false), $address));
    }

    public function testErrorsAreSwallowedAndLogged(): void
    {
        $this->quoteLines->method('getLinesForAddress')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->observer->execute($this->event($this->quote(false), $this->address(Address::ADDRESS_TYPE_SHIPPING)));
    }

    public function testFailedCollectClearsStaleLines(): void
    {
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $this->quoteLines->method('getLinesForAddress')->willThrowException(new \RuntimeException('boom'));
        $this->store->expects($this->once())->method('store')->with($this->identicalTo($address), []);

        $this->observer->execute($this->event($this->quote(false), $address));
    }

    public function testFailingStoreNeverThrows(): void
    {
        $address = $this->address(Address::ADDRESS_TYPE_SHIPPING);
        $this->quoteLines->method('getLinesForAddress')->willReturn([]);
        $this->store->expects($this->exactly(2))->method('store')
            ->willThrowException(new \RuntimeException('store down'));
        $this->logger->expects($this->exactly(2))->method('error');

        $this->observer->execute($this->event($this->quote(false), $address));
    }
}
