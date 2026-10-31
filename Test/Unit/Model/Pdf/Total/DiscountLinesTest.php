<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Model\Pdf\Total;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Tax\Helper\Data;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\SalesDiscountLinesInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Model\Pdf\Total\DiscountLines;
use PixelPerfect\DiscountLines\Service\DocumentLineLabel;
use Psr\Log\LoggerInterface;

if (!class_exists(CollectionFactory::class)) {
    require_once __DIR__ . '/../../../../Stub/TaxOrderCollectionFactory.php';
}

class DiscountLinesTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private DiscountLineRepositoryInterface&MockObject $repository;
    private SalesDiscountLinesInterface&MockObject $salesLines;
    private DiscountLines $total;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $this->salesLines = $this->createMock(SalesDiscountLinesInterface::class);
        $order = $this->createMock(Order::class);
        $order->method('formatPriceTxt')->willReturnCallback(
            static fn($value): string => sprintf('EUR %.2f', $value)
        );
        $source = $this->createMock(Invoice::class);
        $source->method('getOrderId')->willReturn(42);
        $source->method('getStoreId')->willReturn(1);
        $source->method('getDataUsingMethod')->willReturn(-12.0);
        $this->total = new DiscountLines(
            $this->createMock(Data::class),
            $this->createMock(Calculation::class),
            $this->createMock(CollectionFactory::class),
            $this->config,
            $this->repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class),
            ['order' => $order, 'source' => $source, 'title' => 'Discount', 'font_size' => 7]
        );
    }

    public function testReturnsOneEntryPerLine(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->repository->method('hasLines')->willReturn(true);
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_COUPON, 5, 'SAVE10', 'SAVE10', 10.0, 10.0, 0),
            new DiscountLine(DiscountLineInterface::KIND_AUTOMATIC, 6, null, 'Summer sale', 2.0, 2.0, 1),
        ]);

        $entries = $this->total->getTotalsForDisplay();

        $this->assertSame(
            [
                ['amount' => 'EUR -10.00', 'label' => 'Coupon SAVE10:', 'font_size' => 7],
                ['amount' => 'EUR -2.00', 'label' => 'Summer sale:', 'font_size' => 7],
            ],
            $entries
        );
    }

    public function testZeroGiftAndFreeShippingLinesPrintTextInsteadOfZero(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->repository->method('hasLines')->willReturn(true);
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_COUPON, 5, 'SAVE10', 'SAVE10', 12.0, 12.0, 0),
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 1),
            new DiscountLine(DiscountLineInterface::KIND_FREE_SHIPPING, 7, null, 'Free shipping', 0.0, 0.0, 2),
        ]);

        $entries = $this->total->getTotalsForDisplay();

        $this->assertSame(['EUR -12.00', 'Gift', 'Free'], array_column($entries, 'amount'));
        $this->assertSame(['Coupon SAVE10:', 'Gift: FREEBIE:', 'Free shipping:'], array_column($entries, 'label'));
    }

    public function testFallsBackToCoreEntryWithoutLines(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->repository->method('hasLines')->willReturn(false);

        $entries = $this->total->getTotalsForDisplay();

        $this->assertCount(1, $entries);
        $this->assertSame('Discount:', (string)$entries[0]['label']);
        $this->assertSame('EUR -12.00', $entries[0]['amount']);
    }

    public function testFallsBackToCoreEntryWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);

        $entries = $this->total->getTotalsForDisplay();

        $this->assertSame('Discount:', (string)$entries[0]['label']);
    }

    public function testStoredLinesWithoutDocumentShareKeepCoreAmountButHideTheDescription(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->repository->method('hasLines')->willReturn(true);
        $this->salesLines->method('getLines')->willReturn([]);
        $order = $this->createMock(Order::class);
        $order->method('formatPriceTxt')->willReturnCallback(
            static fn($value): string => sprintf('EUR %.2f', $value)
        );
        $order->method('getData')->willReturnMap([['discount_description', null, 'INTERNAL description']]);
        $source = $this->createMock(Invoice::class);
        $source->method('getOrderId')->willReturn(42);
        $source->method('getStoreId')->willReturn(1);
        $source->method('getOrder')->willReturn($order);
        $source->method('getDataUsingMethod')->willReturn(-12.0);
        $total = new DiscountLines(
            $this->createMock(Data::class),
            $this->createMock(Calculation::class),
            $this->createMock(CollectionFactory::class),
            $this->config,
            $this->repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class),
            [
                'order' => $order,
                'source' => $source,
                'title' => 'Discount',
                'title_source_field' => 'discount_description',
                'source_field' => 'discount_amount',
                'font_size' => 7,
            ]
        );

        $entries = $total->getTotalsForDisplay();

        $this->assertCount(1, $entries);
        $this->assertSame('Discount:', (string)$entries[0]['label']);
        $this->assertSame('EUR -12.00', $entries[0]['amount']);
    }
}
