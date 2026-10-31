<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLines\Service\SalesDiscountLines;

class SalesDiscountLinesTest extends TestCase
{
    private DiscountLineRepositoryInterface&MockObject $repository;
    private SalesDiscountLines $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $this->service = new SalesDiscountLines($this->repository, new ProportionalSplitter());
    }

    private function line(string $kind, ?int $ruleId, float $amount, ?string $code = null): DiscountLineInterface
    {
        return new DiscountLine($kind, $ruleId, $code, 'Label', $amount, $amount, 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function order(array $data): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getData')->willReturnCallback(
            static fn($key = '') => $data[$key] ?? null
        );

        return $order;
    }

    /**
     * @param array<int, float> $qtys orderItemId => doc qty
     * @param array<string, mixed> $data
     */
    private function creditmemo(Order $order, array $qtys, array $data): Creditmemo&MockObject
    {
        $items = [];
        foreach ($qtys as $orderItemId => $qty) {
            $orderItem = $this->createMock(OrderItem::class);
            $orderItem->method('getQtyOrdered')->willReturn(3.0);
            $orderItem->method('getParentItemId')->willReturn(null);
            $item = $this->createMock(CreditmemoItem::class);
            $item->method('getOrderItem')->willReturn($orderItem);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }
        $memo = $this->createMock(Creditmemo::class);
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getItems')->willReturn($items);
        $memo->method('getId')->willReturn(null);
        $memo->method('getData')->willReturnCallback(static fn($key = '') => $data[$key] ?? null);

        return $memo;
    }

    public function testOrderReturnsStoredLines(): void
    {
        $lines = [$this->line('coupon', 5, 2.0, 'CODE')];
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $this->repository->method('getOrderLines')->with(42)->willReturn($lines);

        $this->assertSame($lines, $this->service->getLines($order));
    }

    public function testPartialRefundSplitsByQty(): void
    {
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -9.0]);
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE'),
            $this->line('automatic', 6, 3.0),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [5 => ['amount' => 6.0, 'base_amount' => 6.0], 6 => ['amount' => 3.0, 'base_amount' => 3.0]],
        ]);
        $memo = $this->creditmemo(
            $order,
            [10 => 1.0],
            ['order_id' => 1, 'discount_amount' => -3.0, 'base_discount_amount' => -3.0]
        );

        $lines = $this->service->getLines($memo);

        $this->assertCount(2, $lines);
        $this->assertSame(2.0, $lines[0]->getAmount());
        $this->assertSame(1.0, $lines[1]->getAmount());
    }

    public function testLinesSumToCreditmemoDiscountWithOddCents(): void
    {
        $order = $this->order(['discount_amount' => -1.0, 'base_discount_amount' => -1.0]);
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('automatic', 1, 0.34),
            $this->line('automatic', 2, 0.33),
            $this->line('automatic', 3, 0.33),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [
                1 => ['amount' => 0.34, 'base_amount' => 0.34],
                2 => ['amount' => 0.33, 'base_amount' => 0.33],
                3 => ['amount' => 0.33, 'base_amount' => 0.33],
            ],
        ]);
        $memo = $this->creditmemo(
            $order,
            [10 => 1.0],
            ['order_id' => 1, 'discount_amount' => -0.33, 'base_discount_amount' => -0.33]
        );

        $lines = $this->service->getLines($memo);

        $sum = array_sum(array_map(static fn($l): float => $l->getAmount(), $lines));
        $this->assertSame(0.33, round($sum, 2));
    }

    /**
     * @param array<int, float> $qtys orderItemId => doc qty
     * @param array<string, mixed> $data
     * @param array<int, int> $parents orderItemId => parent order item id
     */
    private function invoice(Order $order, array $qtys, array $data, array $parents = []): Invoice&MockObject
    {
        $items = [];
        foreach ($qtys as $orderItemId => $qty) {
            $orderItem = $this->createMock(OrderItem::class);
            $orderItem->method('getQtyOrdered')->willReturn(3.0);
            $orderItem->method('getParentItemId')->willReturn($parents[$orderItemId] ?? null);
            $item = $this->createMock(InvoiceItem::class);
            $item->method('getOrderItem')->willReturn($orderItem);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getOrder')->willReturn($order);
        $invoice->method('getItems')->willReturn($items);
        $invoice->method('getId')->willReturn(null);
        $invoice->method('getData')->willReturnCallback(static fn($key = '') => $data[$key] ?? null);

        return $invoice;
    }

    /**
     * @return array<int, array<int, array{amount: float, base_amount: float}>>
     */
    private function itemAmounts(): array
    {
        return [
            10 => [5 => ['amount' => 6.0, 'base_amount' => 6.0], 6 => ['amount' => 3.0, 'base_amount' => 3.0]],
        ];
    }

    public function testPartialInvoiceSplitsByQty(): void
    {
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -9.0]);
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE'),
            $this->line('automatic', 6, 3.0),
        ]);
        $this->repository->method('getItemAmounts')->willReturn($this->itemAmounts());
        $invoice = $this->invoice(
            $order,
            [10 => 2.0],
            ['order_id' => 1, 'discount_amount' => -6.0, 'base_discount_amount' => -6.0]
        );

        $lines = $this->service->getLines($invoice);

        $this->assertSame([4.0, 2.0], array_map(static fn($l): float => $l->getAmount(), $lines));
    }

    public function testTwoPartialInvoicesSumToOrderDiscount(): void
    {
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -9.0]);
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE'),
            $this->line('automatic', 6, 3.0),
        ]);
        $this->repository->method('getItemAmounts')->willReturn($this->itemAmounts());
        $first = $this->invoice(
            $order,
            [10 => 1.0],
            ['order_id' => 1, 'discount_amount' => -3.0, 'base_discount_amount' => -3.0]
        );
        $second = $this->invoice(
            $order,
            [10 => 2.0],
            ['order_id' => 1, 'discount_amount' => -6.0, 'base_discount_amount' => -6.0]
        );

        $sum = 0.0;
        foreach ([$first, $second] as $invoice) {
            foreach ($this->service->getLines($invoice) as $line) {
                $sum += $line->getAmount();
            }
        }

        $this->assertSame(9.0, round($sum, 2));
    }

    public function testChildItemIsSkippedWhenParentCarriesRows(): void
    {
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -9.0]);
        $this->repository->method('getOrderLines')->willReturn([$this->line('automatic', 6, 9.0)]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [6 => ['amount' => 9.0, 'base_amount' => 9.0]],
            11 => [6 => ['amount' => 9.0, 'base_amount' => 9.0]],
        ]);
        $invoice = $this->invoice(
            $order,
            [10 => 3.0, 11 => 3.0],
            ['order_id' => 1, 'discount_amount' => -9.0, 'base_discount_amount' => -9.0],
            [11 => 10]
        );

        $lines = $this->service->getLines($invoice);

        $this->assertSame(9.0, $lines[0]->getAmount());
    }

    public function testOddCentsCreditmemoIsReconciledToDocumentDiscount(): void
    {
        $order = $this->order(['discount_amount' => -1.0, 'base_discount_amount' => -1.0]);
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('automatic', 1, 0.34),
            $this->line('automatic', 2, 0.33),
            $this->line('automatic', 3, 0.33),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [
                1 => ['amount' => 0.34, 'base_amount' => 0.34],
                2 => ['amount' => 0.33, 'base_amount' => 0.33],
                3 => ['amount' => 0.33, 'base_amount' => 0.33],
            ],
        ]);
        // 1 of 3 gives 0.11 + 0.11 + 0.11 = 0.33 per rule share, core says 0.34.
        $memo = $this->creditmemo(
            $order,
            [10 => 1.0],
            ['order_id' => 1, 'discount_amount' => -0.34, 'base_discount_amount' => -0.34]
        );

        $lines = $this->service->getLines($memo);

        $sum = array_sum(array_map(static fn($l): float => $l->getAmount(), $lines));
        $this->assertSame(0.34, round($sum, 2));
    }

    public function testFreeShippingScalesWithDocumentShipping(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('free_shipping', 7, 4.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => -4.0, 'base_discount_amount' => -4.0, 'shipping_amount' => 10.0]);
        $half = ['order_id' => 1, 'discount_amount' => -2.0, 'base_discount_amount' => -2.0, 'shipping_amount' => 5.0];
        $none = ['order_id' => 1, 'discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => 0.0];

        $lines = $this->service->getLines($this->creditmemo($order, [], $half));
        $this->assertSame(2.0, $lines[0]->getAmount());
        $this->assertSame([], $this->service->getLines($this->creditmemo($order, [], $none)));
    }

    public function testZeroFreeShippingLineStaysOnInvoicesOnly(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('free_shipping', 7, 0.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => 0.0]);
        $data = ['order_id' => 1, 'discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => 0.0];

        $this->assertCount(1, $this->service->getLines($this->invoice($order, [], $data)));
        $this->assertSame([], $this->service->getLines($this->creditmemo($order, [], $data)));
    }

    public function testLinesAreLoadedOncePerSource(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $this->repository->expects($this->once())->method('getOrderLines')->willReturn([]);

        $this->service->getLines($order);
        $this->service->getLines($order);
    }

    public function testGenericLineIsSplitByDiscountShare(): void
    {
        $order = $this->order(['discount_amount' => -10.0, 'base_discount_amount' => -10.0]);
        $this->repository->method('getOrderLines')->willReturn([$this->line('gift_card', null, 10.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $memo = $this->creditmemo(
            $order,
            [],
            ['order_id' => 1, 'discount_amount' => -2.5, 'base_discount_amount' => -2.5]
        );

        $lines = $this->service->getLines($memo);

        $this->assertSame(2.5, $lines[0]->getAmount());
    }
}
