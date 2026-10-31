<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
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

    public function testFreeShippingOnlyOnFirstDocumentCarryingShipping(): void
    {
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('free_shipping', 7, 4.0),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $first = $this->order(['discount_amount' => -4.0, 'base_discount_amount' => -4.0, 'shipping_refunded' => 0]);
        $second = $this->order(['discount_amount' => -4.0, 'base_discount_amount' => -4.0, 'shipping_refunded' => 5.0]);
        $data = [
            'order_id' => 1, 'discount_amount' => -4.0, 'base_discount_amount' => -4.0, 'shipping_amount' => 5.0,
        ];

        $this->assertCount(1, $this->service->getLines($this->creditmemo($first, [], $data)));
        $this->assertSame([], $this->service->getLines($this->creditmemo($second, [], $data)));
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
