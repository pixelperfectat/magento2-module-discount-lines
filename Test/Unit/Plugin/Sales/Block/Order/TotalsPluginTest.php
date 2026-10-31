<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Plugin\Sales\Block\Order;

use Magento\Framework\DataObject;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\SalesDiscountLinesInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Plugin\Sales\Block\Order\TotalsPlugin;
use PixelPerfect\DiscountLines\Service\DocumentLineLabel;
use Psr\Log\LoggerInterface;

class TotalsPluginTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private DiscountLineRepositoryInterface&MockObject $repository;
    private SalesDiscountLinesInterface&MockObject $salesLines;
    private Totals&MockObject $block;
    private TotalsPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $this->repository->method('hasLines')->willReturn(true);
        $this->salesLines = $this->createMock(SalesDiscountLinesInterface::class);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $this->block = $this->createMock(Totals::class);
        $this->block->method('getSource')->willReturn($order);
        $this->plugin = new TotalsPlugin(
            $this->config,
            $this->repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return array<string, DataObject>
     */
    private function coreTotals(): array
    {
        return [
            'subtotal' => new DataObject(['code' => 'subtotal', 'value' => 100.0, 'label' => 'Subtotal']),
            'discount' => new DataObject(['code' => 'discount', 'value' => -12.0, 'label' => 'Discount']),
            'shipping' => new DataObject(['code' => 'shipping', 'value' => 5.0, 'label' => 'Shipping']),
            'grand_total' => new DataObject(['code' => 'grand_total', 'value' => 93.0, 'label' => 'Grand Total']),
        ];
    }

    public function testReplacesDiscountRowWithOneRowPerLine(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_COUPON, 5, 'SAVE10', 'SAVE10', 10.0, 10.0, 0),
            new DiscountLine(DiscountLineInterface::KIND_AUTOMATIC, 6, null, 'Summer sale', 2.0, 2.0, 1),
        ]);

        $result = $this->plugin->afterGetTotals($this->block, $this->coreTotals());

        $this->assertSame(
            ['subtotal', 'pp_discount_line_0', 'pp_discount_line_1', 'shipping', 'grand_total'],
            array_keys($result)
        );
        $row = $result['pp_discount_line_0'];
        $this->assertSame(-10.0, $row->getValue());
        $this->assertSame(-10.0, $row->getBaseValue());
        $this->assertSame('Coupon SAVE10', (string)$row->getLabel());
        $this->assertSame(
            ['kind', 'rule_id', 'code', 'label', 'amount', 'base_amount'],
            array_keys($row->getDiscountLine())
        );
        $this->assertSame('Summer sale', (string)$result['pp_discount_line_1']->getLabel());
    }

    public function testFooterAreaIsUntouched(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_COUPON, 5, 'SAVE10', 'SAVE10', 10.0, 10.0, 0),
        ]);
        $footer = [new DataObject(['code' => 'paid', 'value' => 1.0, 'label' => 'Paid'])];

        $this->assertSame($footer, $this->plugin->afterGetTotals($this->block, $footer, 'footer'));
    }

    public function testReplacesInPlaceForEmptyArea(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_COUPON, 5, 'SAVE10', 'SAVE10', 10.0, 10.0, 0),
        ]);
        $core = array_values($this->coreTotals());

        $result = $this->plugin->afterGetTotals($this->block, $core, '');

        $codes = array_map(static fn(DataObject $row): string => $row->getCode(), $result);
        $this->assertSame(['subtotal', 'pp_discount_line_0', 'shipping', 'grand_total'], array_values($codes));
    }

    public function testOrderWithoutStoredLinesKeepsCoreRow(): void
    {
        $repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $repository->method('hasLines')->willReturn(false);
        $plugin = new TotalsPlugin(
            $this->config,
            $repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class)
        );
        $core = $this->coreTotals();

        $this->assertSame($core, $plugin->afterGetTotals($this->block, $core));
    }

    public function testDisabledKeepsCoreRow(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $plugin = new TotalsPlugin(
            $config,
            $this->repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class)
        );
        $core = $this->coreTotals();

        $this->assertSame($core, $plugin->afterGetTotals($this->block, $core));
    }

    public function testGiftLineValueIsZeroAndLabelSaysGift(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 0),
        ]);

        $result = $this->plugin->afterGetTotals($this->block, $this->coreTotals());

        $this->assertSame(0.0, $result['pp_discount_line_0']->getValue());
        $this->assertSame('Gift: FREEBIE', (string)$result['pp_discount_line_0']->getLabel());
    }

    /**
     * @return array<string, DataObject>
     */
    private function totalsWithoutDiscount(): array
    {
        $core = $this->coreTotals();
        unset($core['discount']);

        return $core;
    }

    public function testGiftOnlyOrderGetsRowInsertedForDefaultArea(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 0),
        ]);

        $result = $this->plugin->afterGetTotals($this->block, $this->totalsWithoutDiscount(), '');

        $this->assertSame(
            ['subtotal', 'shipping', 'pp_discount_line_0', 'grand_total'],
            array_keys($result)
        );
        $this->assertSame('Gift: FREEBIE', (string)$result['pp_discount_line_0']->getLabel());
        $this->assertSame(0.0, $result['pp_discount_line_0']->getValue());
    }

    public function testRowInsertedForNullArea(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 0),
        ]);

        $result = $this->plugin->afterGetTotals($this->block, $this->totalsWithoutDiscount());

        $this->assertArrayHasKey('pp_discount_line_0', $result);
    }

    public function testFooterAreaStillUntouched(): void
    {
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 0),
        ]);
        $footer = ['paid' => new DataObject(['code' => 'paid', 'value' => 1.0, 'label' => 'Paid'])];

        $this->assertSame($footer, $this->plugin->afterGetTotals($this->block, $footer, 'footer'));
        $core = $this->totalsWithoutDiscount();
        $this->assertSame($core, $this->plugin->afterGetTotals($this->block, $core, 'footer'));
    }

    public function testNoRowInsertedWhenSourceHasDiscount(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getData')->willReturnMap([['discount_amount', null, -3.0]]);
        $block = $this->createMock(Totals::class);
        $block->method('getSource')->willReturn($order);
        $this->salesLines->method('getLines')->willReturn([
            new DiscountLine(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0, 0),
        ]);
        $core = $this->totalsWithoutDiscount();

        $this->assertSame($core, $this->plugin->afterGetTotals($block, $core, ''));
    }

    public function testNoRowInsertedWithoutStoredLines(): void
    {
        $repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $repository->method('hasLines')->willReturn(false);
        $plugin = new TotalsPlugin(
            $this->config,
            $repository,
            $this->salesLines,
            new DocumentLineLabel(),
            $this->createMock(LoggerInterface::class)
        );
        $core = $this->totalsWithoutDiscount();

        $this->assertSame($core, $plugin->afterGetTotals($this->block, $core, ''));
    }

    public function testExceptionKeepsCoreRows(): void
    {
        $this->salesLines->method('getLines')->willThrowException(new \RuntimeException('boom'));
        $core = $this->coreTotals();

        $this->assertSame($core, $this->plugin->afterGetTotals($this->block, $core));
    }

    public function testUninitialisedBlockResultIsPassedThrough(): void
    {
        $this->assertNull($this->plugin->afterGetTotals($this->block, null));
    }
}
