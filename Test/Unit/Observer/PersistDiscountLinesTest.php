<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Observer;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\SalesRule\Api\Data\DiscountDataInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Observer\PersistDiscountLines;
use Psr\Log\LoggerInterface;

class PersistDiscountLinesTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private QuoteDiscountLinesInterface&MockObject $quoteLines;
    private DiscountLineRepositoryInterface&MockObject $repository;
    private LoggerInterface&MockObject $logger;
    private PersistDiscountLines $observer;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->quoteLines = $this->createMock(QuoteDiscountLinesInterface::class);
        $this->repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->observer = new PersistDiscountLines(
            $this->config,
            $this->quoteLines,
            $this->repository,
            $this->logger
        );
    }

    private function discount(int $ruleId, float $amount, float $baseAmount): RuleDiscountInterface
    {
        $data = $this->createMock(DiscountDataInterface::class);
        $data->method('getAmount')->willReturn($amount);
        $data->method('getBaseAmount')->willReturn($baseAmount);
        $discount = $this->createMock(RuleDiscountInterface::class);
        $discount->method('getRuleID')->willReturn($ruleId);
        $discount->method('getDiscountData')->willReturn($data);

        return $discount;
    }

    /**
     * @param RuleDiscountInterface[] $discounts
     */
    private function quoteItem(int $id, array $discounts): QuoteItem
    {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $extension->method('getDiscounts')->willReturn($discounts);
        $item = $this->createMock(QuoteItem::class);
        $item->method('getId')->willReturn($id);
        $item->method('getExtensionAttributes')->willReturn($extension);

        return $item;
    }

    private function orderItem(int $itemId, int $quoteItemId): OrderItem
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getQuoteItemId')->willReturn($quoteItemId);

        return $item;
    }

    /**
     * @param OrderItem[] $orderItems
     * @param QuoteItem[] $quoteItems
     */
    private function event(array $orderItems, array $quoteItems): Observer
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(500);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getAllItems')->willReturn($orderItems);
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($quoteItems);

        return new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]);
    }

    private function line(string $kind, ?int $ruleId, ?string $code, string $label, float $amount, int $sort): DiscountLine
    {
        return new DiscountLine($kind, $ruleId, $code, $label, $amount, $amount, $sort);
    }

    public function testWritesOneRowPerRulePerItem(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 3.0, 0),
            $this->line(DiscountLineInterface::KIND_AUTOMATIC, 2, null, 'Spring', 2.0, 1),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11), $this->orderItem(902, 12)],
            [
                $this->quoteItem(11, [$this->discount(1, 2.0, 2.0), $this->discount(2, 1.0, 1.0)]),
                $this->quoteItem(12, [$this->discount(1, 1.0, 1.0), $this->discount(2, 1.0, 1.0)]),
            ]
        );

        $captured = null;
        $this->repository->expects($this->once())->method('saveForOrder')
            ->with(500, $this->callback(function (array $rows) use (&$captured): bool {
                $captured = $rows;

                return true;
            }));

        $this->observer->execute($event);

        $item = array_values(array_filter($captured, static fn (array $r): bool => $r['order_item_id'] !== null));
        $orderLevel = array_values(array_filter($captured, static fn (array $r): bool => $r['order_item_id'] === null));
        $this->assertCount(4, $item);
        $this->assertCount(2, $orderLevel);
        $byKey = [];
        foreach ($item as $row) {
            $byKey[$row['order_item_id'] . ':' . $row['rule_id']] = $row['amount'];
        }
        $this->assertSame(['901:1' => 2.0, '901:2' => 1.0, '902:1' => 1.0, '902:2' => 1.0], $byKey);
    }

    public function testItemRowsAbsorbRoundingDifferenceIntoLargestRow(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_AUTOMATIC, 2, null, 'Spring', 1.0, 0),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11), $this->orderItem(902, 12), $this->orderItem(903, 13)],
            [
                $this->quoteItem(11, [$this->discount(2, 0.33, 0.33)]),
                $this->quoteItem(12, [$this->discount(2, 0.33, 0.33)]),
                $this->quoteItem(13, [$this->discount(2, 0.33, 0.33)]),
            ]
        );

        $captured = [];
        $this->repository->method('saveForOrder')->willReturnCallback(
            function (int $id, array $rows) use (&$captured): void {
                $captured = $rows;
            }
        );
        $this->observer->execute($event);

        $sum = 0.0;
        foreach ($captured as $row) {
            if ($row['order_item_id'] !== null) {
                $sum += $row['amount'];
            }
        }
        $this->assertSame(1.0, round($sum, 2));
    }

    public function testWritesOrderLevelRowForFreeShipping(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, 3, 'SHIPFREE', 'SHIPFREE', 5.0, 0),
        ]);
        $event = $this->event([$this->orderItem(901, 11)], [$this->quoteItem(11, [])]);

        $this->repository->expects($this->once())->method('saveForOrder')->with(
            500,
            [[
                'order_item_id' => null,
                'rule_id' => 3,
                'kind' => 'free_shipping',
                'code' => 'SHIPFREE',
                'label' => 'SHIPFREE',
                'amount' => 5.0,
                'base_amount' => 5.0,
                'sort_order' => 0,
            ]]
        );

        $this->observer->execute($event);
    }

    public function testLabelsSnapshotted(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_AUTOMATIC, 2, null, 'Spring sale', 1.0, 0),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11)],
            [$this->quoteItem(11, [$this->discount(2, 1.0, 1.0)])]
        );

        $captured = [];
        $this->repository->method('saveForOrder')->willReturnCallback(
            function (int $id, array $rows) use (&$captured): void {
                $captured = $rows;
            }
        );
        $this->observer->execute($event);

        foreach ($captured as $row) {
            $this->assertSame('Spring sale', $row['label']);
            $this->assertSame('automatic', $row['kind']);
        }
        $this->assertCount(2, $captured);
    }

    public function testNothingWrittenWhenDisabled(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $observer = new PersistDiscountLines($config, $this->quoteLines, $this->repository, $this->logger);
        $this->quoteLines->expects($this->never())->method('getLines');
        $this->repository->expects($this->never())->method('saveForOrder');

        $observer->execute($this->event([], []));
    }

    public function testNothingWrittenWithoutLines(): void
    {
        $this->quoteLines->method('getLines')->willReturn([]);
        $this->repository->expects($this->never())->method('saveForOrder');

        $this->observer->execute($this->event([], []));
    }

    public function testRepositoryFailureIsLoggedAndSwallowed(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 1.0, 0),
        ]);
        $this->repository->expects($this->once())->method('saveForOrder')
            ->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');

        $this->observer->execute($this->event([], []));
        $this->addToAssertionCount(1);
    }

    public function testRuleWithItemDiscountAndFreeShippingKeepsItemRowsOnRuleLine(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 2.0, 0),
            $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, 1, 'CODE-A', 'CODE-A', 5.0, 1),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11)],
            [$this->quoteItem(11, [$this->discount(1, 2.0, 2.0)])]
        );

        $captured = [];
        $this->repository->method('saveForOrder')->willReturnCallback(
            function (int $id, array $rows) use (&$captured): void {
                $captured = $rows;
            }
        );
        $this->observer->execute($event);

        $itemRows = array_values(array_filter($captured, static fn (array $r): bool => $r['order_item_id'] !== null));
        $this->assertCount(1, $itemRows);
        $this->assertSame('coupon', $itemRows[0]['kind']);
        $this->assertSame(2.0, $itemRows[0]['amount']);
        $this->assertCount(3, $captured);
    }

    public function testFailureIsLoggedAndSwallowed(): void
    {
        $this->quoteLines->method('getLines')->willThrowException(new \RuntimeException('boom'));
        $this->repository->expects($this->never())->method('saveForOrder');
        $this->logger->expects($this->once())->method('error');

        $this->observer->execute($this->event([], []));
        $this->addToAssertionCount(1);
    }
}
