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
use PixelPerfect\DiscountLines\Api\Service\ProportionalSplitterInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;
use PixelPerfect\DiscountLines\Service\SalesDiscountLines;

class SalesDiscountLinesTest extends TestCase
{
    private DiscountLineRepositoryInterface&MockObject $repository;
    private SalesDiscountLines $service;

    /** @var array<int, array{amounts: float[], base: float[], target: float, baseTarget: float}> */
    private array $splits = [];

    protected function setUp(): void
    {
        $this->repository = $this->createMock(DiscountLineRepositoryInterface::class);
        $real = new ProportionalSplitter();
        $splitter = $this->createMock(ProportionalSplitterInterface::class);
        $splitter->method('split')->willReturnCallback(
            function (array $lines, float $target, float $baseTarget) use ($real): array {
                $this->splits[] = [
                    'amounts' => $this->amounts($lines),
                    'base' => $this->baseAmounts($lines),
                    'target' => $target,
                    'baseTarget' => $baseTarget,
                ];

                return $real->split($lines, $target, $baseTarget);
            }
        );
        $this->service = new SalesDiscountLines($this->repository, $splitter);
    }

    private function line(
        string $kind,
        ?int $ruleId,
        float $amount,
        ?string $code = null,
        ?float $baseAmount = null
    ): DiscountLineInterface {
        return new DiscountLine($kind, $ruleId, $code, 'Label', $amount, $baseAmount ?? $amount, 0);
    }

    /**
     * @param DiscountLineInterface[] $lines
     * @return float[]
     */
    private function amounts(array $lines): array
    {
        return array_values(array_map(static fn(DiscountLineInterface $l): float => $l->getAmount(), $lines));
    }

    /**
     * @param DiscountLineInterface[] $lines
     * @return float[]
     */
    private function baseAmounts(array $lines): array
    {
        return array_values(array_map(static fn(DiscountLineInterface $l): float => $l->getBaseAmount(), $lines));
    }

    /**
     * Order item mocks for documents: orderItemId => [doc qty, ordered qty, parent order item id].
     *
     * @param array<int, array{0: float, 1?: float, 2?: int|null}> $rows
     * @return array<int, MockObject>
     */
    private function documentItems(string $itemClass, array $rows): array
    {
        $items = [];
        foreach ($rows as $orderItemId => $row) {
            $orderItem = $this->createMock(OrderItem::class);
            $orderItem->method('getQtyOrdered')->willReturn($row[1] ?? 3.0);
            $orderItem->method('getParentItemId')->willReturn($row[2] ?? null);
            $item = $this->createMock($itemClass);
            $item->method('getOrderItem')->willReturn($orderItem);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($row[0]);
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<int, MockObject> $items
     * @param array<string, mixed> $data
     */
    private function document(string $class, Order $order, array $items, array $data): Invoice|Creditmemo
    {
        $document = $this->createMock($class);
        $document->method('getOrder')->willReturn($order);
        $document->method('getItems')->willReturn($items);
        $document->method('getData')->willReturnCallback(static fn($key = '') => $data[$key] ?? null);

        return $document;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function order(array $data, int $id = 0): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getData')->willReturnCallback(
            static fn($key = '') => $data[$key] ?? null
        );

        return $order;
    }

    private function placedOrder(float $discount): Order&MockObject
    {
        return $this->order(['discount_amount' => -$discount, 'base_discount_amount' => -$discount], 42);
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
        $order = $this->placedOrder(2.0);
        $this->repository->method('getOrderLines')->with(42)->willReturn($lines);

        $this->assertSame($lines, $this->service->getLines($order));
    }

    public function testOrderLinesNotMatchingTheOrderDiscountAreScaledToIt(): void
    {
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE'),
            $this->line('automatic', 6, 2.0),
        ]);

        $lines = $this->service->getLines($this->placedOrder(10.0));

        $this->assertSame([7.5, 2.5], array_map(static fn($line) => $line->getAmount(), $lines));
        $this->assertSame([7.5, 2.5], array_map(static fn($line) => $line->getBaseAmount(), $lines));
    }

    public function testOrderLinesWithoutMoneyFallBackForADiscountedOrder(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('gift', 9, 0.0, 'FREEBIE')]);

        $this->assertSame([], $this->service->getLines($this->placedOrder(10.0)));
    }

    public function testOrderMoneyLinesFallBackForAnOrderWithoutDiscount(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, 2.0, 'CODE')]);

        $this->assertSame([], $this->service->getLines($this->placedOrder(0.0)));
    }

    public function testZeroLinesOfAnOrderWithoutDiscountAreKept(): void
    {
        $lines = [$this->line('gift', 9, 0.0, 'FREEBIE')];
        $this->repository->method('getOrderLines')->willReturn($lines);

        $this->assertSame($lines, $this->service->getLines($this->placedOrder(0.0)));
    }

    public function testDocumentWithOnlyZeroLinesButAMoneyDiscountFallsBack(): void
    {
        $order = $this->order(['discount_amount' => -5.0, 'base_discount_amount' => -5.0, 'shipping_amount' => 0.0]);
        $this->repository->method('getOrderLines')->willReturn([$this->line('gift', 9, 0.0, 'FREEBIE')]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $data = ['order_id' => 1, 'discount_amount' => -5.0, 'base_discount_amount' => -5.0, 'shipping_amount' => 0.0];

        $this->assertSame([], $this->service->getLines($this->invoice($order, [], $data)));
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

        $this->assertSame([9.0], $this->splits[0]['amounts']);
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

    public function testZeroGiftLineStaysOnInvoicesButNotOnCreditMemos(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('gift', 9, 0.0, 'FREEBIE')]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => 0.0]);
        $data = ['order_id' => 1, 'discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => 0.0];

        $this->assertCount(1, $this->service->getLines($this->invoice($order, [], $data)));
        $this->assertSame([], $this->service->getLines($this->creditmemo($order, [], $data)));
    }

    public function testLinesAreLoadedOncePerSource(): void
    {
        $order = $this->placedOrder(2.0);
        $this->repository->expects($this->once())->method('getOrderLines')
            ->willReturn([$this->line('coupon', 5, 2.0, 'CODE')]);

        $this->service->getLines($order);
        $this->service->getLines($order);
    }

    public function testEmptyResultIsNotMemoized(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $this->repository->expects($this->exactly(2))->method('getOrderLines')->willReturn([]);

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

    public function testDocumentWithoutStoredLinesSkipsTheItemAmountQuery(): void
    {
        $this->repository->expects($this->once())->method('getOrderLines')->with(7)->willReturn([]);
        $this->repository->expects($this->never())->method('getItemAmounts');
        $invoice = $this->document(Invoice::class, $this->order([]), [], ['order_id' => '7']);

        $this->assertSame([], $this->service->getLines($invoice));
    }

    /**
     * @return array<string, array{float, float, float, float}>
     */
    public static function centMatchProvider(): array
    {
        return [
            'order discount with a sub-cent remainder' => [-3.004, -6.0, 3.0, 6.0],
            'order discount rounding up to the line sum' => [-2.996, -6.0, 3.0, 6.0],
            'order discount with cents' => [-3.04, -6.0, 3.04, 6.0],
            'base discount with a sub-cent remainder' => [-3.0, -6.004, 3.0, 6.0],
            'base discount rounding up to the line sum' => [-3.0, -5.996, 3.0, 6.0],
            'base discount with cents' => [-3.0, -6.04, 3.0, 6.04],
            'line sum with a sub-cent remainder' => [-3.0, -6.0, 3.004, 6.0],
            'line sum rounding up to the discount' => [-3.0, -6.0, 2.996, 6.0],
            'base line sum with a sub-cent remainder' => [-3.0, -6.0, 3.0, 6.004],
            'base line sum rounding up to the discount' => [-3.0, -6.0, 3.0, 5.996],
        ];
    }

    /**
     * @dataProvider centMatchProvider
     */
    public function testOrderLinesMatchingTheDiscountToTheCentAreReturnedAsStored(
        float $discount,
        float $baseDiscount,
        float $amount,
        float $baseAmount
    ): void {
        $lines = [$this->line('coupon', 5, $amount, 'CODE', $baseAmount)];
        $this->repository->method('getOrderLines')->willReturn($lines);
        $order = $this->order(['discount_amount' => $discount, 'base_discount_amount' => $baseDiscount], 42);

        $this->assertSame($lines, $this->service->getLines($order));
        $this->assertSame([], $this->splits);
    }

    public function testSeveralOrderLinesAddingUpToTheDiscountAreReturnedAsStored(): void
    {
        $lines = [$this->line('coupon', 5, 2.0, 'CODE', 4.0), $this->line('automatic', 6, 1.0, null, 2.0)];
        $this->repository->method('getOrderLines')->willReturn($lines);
        $order = $this->order(['discount_amount' => -3.0, 'base_discount_amount' => -6.0], 42);

        $this->assertSame($lines, $this->service->getLines($order));
    }

    public function testOrderLinesMatchingOnlyInOrderCurrencyAreScaledInBaseCurrency(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, 3.0, 'CODE', 6.0)]);
        $order = $this->order(['discount_amount' => -3.0, 'base_discount_amount' => -7.0], 42);

        $lines = $this->service->getLines($order);

        $this->assertSame([3.0], $this->amounts($lines));
        $this->assertSame([7.0], $this->baseAmounts($lines));
    }

    /**
     * @return array<string, array{float, float, float, float}>
     */
    public static function oneSidedProvider(): array
    {
        return [
            'lines without order-currency money' => [0.0, 2.0, -3.0, -6.0],
            'lines without base money' => [2.0, 0.0, -3.0, -6.0],
            'lines with only epsilon order-currency money' => [0.00001, 6.0, -3.0, -6.0],
            'lines with only epsilon base money' => [3.0, 0.00001, -3.0, -6.0],
            'order without base discount' => [3.0, 6.0, -3.0, 0.0],
            'order without order-currency discount' => [3.0, 6.0, 0.0, -6.0],
        ];
    }

    /**
     * Invariant A: lines that cannot add up to the discount in both currencies give way to the core row.
     *
     * @dataProvider oneSidedProvider
     */
    public function testMoneyInOnlyOneCurrencyFallsBackToTheCoreRow(
        float $amount,
        float $baseAmount,
        float $discount,
        float $baseDiscount
    ): void {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, $amount, 'CODE', $baseAmount)]);
        $order = $this->order(['discount_amount' => $discount, 'base_discount_amount' => $baseDiscount], 42);

        $this->assertSame([], $this->service->getLines($order));
    }

    public function testZeroLineAfterAMoneyLineDoesNotHideTheMoney(): void
    {
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE', 12.0),
            $this->line('gift', 9, 0.0, 'FREEBIE'),
        ]);
        $order = $this->order(['discount_amount' => -10.0, 'base_discount_amount' => -20.0], 42);

        $lines = $this->service->getLines($order);

        $this->assertSame([10.0, 0.0], $this->amounts($lines));
        $this->assertSame([20.0, 0.0], $this->baseAmounts($lines));
    }

    /**
     * @return array<string, array{float, float, float, float, float[], float[]}>
     */
    public static function shareProvider(): array
    {
        return [
            'no order-currency discount on the order' => [0.0, -20.0, 0.0, -10.0, [0.0], [10.0]],
            'epsilon order discount counts as none' => [-0.00001, -20.0, -0.00001, -10.0, [0.0], [10.0]],
            'epsilon document discount counts as none' => [-10.0, -20.0, -0.00001, -10.0, [0.0], [10.0]],
            'epsilon base document discount counts as none' => [-10.0, -20.0, -5.0, -0.00001, [5.0], [0.0]],
        ];
    }

    /**
     * No money is invented in a currency the order or document does not discount.
     *
     * @dataProvider shareProvider
     * @param float[] $amounts
     * @param float[] $baseAmounts
     */
    public function testShareOfADiscountMissingInOneCurrencyIsZero(
        float $orderDiscount,
        float $orderBaseDiscount,
        float $docDiscount,
        float $docBaseDiscount,
        array $amounts,
        array $baseAmounts
    ): void {
        $this->repository->method('getOrderLines')->willReturn([$this->line('gift_card', null, 10.0, null, 20.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => $orderDiscount, 'base_discount_amount' => $orderBaseDiscount]);
        $data = ['order_id' => 1, 'discount_amount' => $docDiscount, 'base_discount_amount' => $docBaseDiscount];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, [], $data));

        $this->assertSame($amounts, $this->amounts($lines));
        $this->assertSame($baseAmounts, $this->baseAmounts($lines));
    }

    public function testLinesWithoutItemRowsAreSplitByTheDocumentShareRoundedToCents(): void
    {
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('gift_card', null, 10.0, null, 20.0),
            $this->line('store_credit', null, 5.0, null, 10.0),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => -15.0, 'base_discount_amount' => -30.0]);
        $data = ['order_id' => 1, 'discount_amount' => -5.0, 'base_discount_amount' => -10.0];

        $lines = $this->service->getLines($this->document(Creditmemo::class, $order, [], $data));

        $this->assertSame([3.33, 1.67], $this->splits[0]['amounts']);
        $this->assertSame([6.67, 3.33], $this->splits[0]['base']);
        $this->assertSame(5.0, $this->splits[0]['target']);
        $this->assertSame(10.0, $this->splits[0]['baseTarget']);
        $this->assertSame([3.33, 1.67], $this->amounts($lines));
        $this->assertSame([6.67, 3.33], $this->baseAmounts($lines));
    }

    public function testRuleLineIsSplitByItsItemRowsNotByTheDocumentShare(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, 10.0, 'CODE', 20.0)]);
        $this->repository->method('getItemAmounts')->willReturn([10 => [5 => ['amount' => 10.0, 'base_amount' => 20.0]]]);
        $order = $this->order(['discount_amount' => -10.0, 'base_discount_amount' => -20.0]);
        $items = $this->documentItems(InvoiceItem::class, [10 => [1.0]]);
        $data = ['order_id' => 1, 'discount_amount' => -4.0, 'base_discount_amount' => -8.0];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, $items, $data));

        $this->assertSame([3.33], $this->splits[0]['amounts']);
        $this->assertSame([6.67], $this->splits[0]['base']);
        $this->assertSame([4.0], $this->amounts($lines));
        $this->assertSame([8.0], $this->baseAmounts($lines));
    }

    public function testRuleAmountsAddUpOverAllDocumentItemsSkippingItemsWithoutRowsOrQty(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, 9.0, 'CODE', 18.0)]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [5 => ['amount' => 6.0, 'base_amount' => 12.0]],
            11 => [5 => ['amount' => 3.0, 'base_amount' => 6.0]],
            13 => [5 => ['amount' => 1.0, 'base_amount' => 2.0]],
        ]);
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -18.0]);
        $items = $this->documentItems(InvoiceItem::class, [
            12 => [1.0],
            13 => [1.0, 0.0],
            10 => [3.0],
            11 => [3.0],
        ]);
        $data = ['order_id' => 1, 'discount_amount' => -9.5, 'base_discount_amount' => -19.0];

        $this->service->getLines($this->document(Invoice::class, $order, $items, $data));

        $this->assertSame([9.0], $this->splits[0]['amounts']);
        $this->assertSame([18.0], $this->splits[0]['base']);
    }

    public function testChildItemCountsWhenItsParentCarriesNoRows(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('automatic', 6, 9.0, null, 18.0)]);
        $this->repository->method('getItemAmounts')->willReturn([11 => [6 => ['amount' => 9.0, 'base_amount' => 18.0]]]);
        $order = $this->order(['discount_amount' => -9.0, 'base_discount_amount' => -18.0]);
        $items = $this->documentItems(InvoiceItem::class, [10 => [3.0], 11 => [3.0, 3.0, 10]]);
        $data = ['order_id' => 1, 'discount_amount' => -9.0, 'base_discount_amount' => -18.0];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, $items, $data));

        $this->assertSame([9.0], $this->amounts($lines));
        $this->assertSame([18.0], $this->baseAmounts($lines));
    }

    public function testRuleLineWhoseItemsAreNotOnTheDocumentIsDropped(): void
    {
        $this->repository->method('getOrderLines')->willReturn([
            $this->line('coupon', 5, 6.0, 'CODE', 12.0),
            $this->line('gift', 9, 0.0, 'FREEBIE'),
        ]);
        $this->repository->method('getItemAmounts')->willReturn([
            10 => [5 => ['amount' => 6.0, 'base_amount' => 12.0]],
            11 => [9 => ['amount' => 0.0, 'base_amount' => 0.0]],
        ]);
        $order = $this->order(['discount_amount' => -6.0, 'base_discount_amount' => -12.0]);
        $items = $this->documentItems(InvoiceItem::class, [10 => [3.0]]);
        $data = ['order_id' => 1, 'discount_amount' => -6.0, 'base_discount_amount' => -12.0];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, $items, $data));

        $this->assertSame(['coupon'], array_map(static fn($l): string => $l->getKind(), $lines));
        $this->assertSame([6.0], $this->amounts($lines));
        $this->assertSame([12.0], $this->baseAmounts($lines));
    }

    public function testRuleShareRoundingToZeroInOneCurrencyKeepsTheLine(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('coupon', 5, 0.01, 'CODE', 0.02)]);
        $this->repository->method('getItemAmounts')->willReturn([10 => [5 => ['amount' => 0.01, 'base_amount' => 0.02]]]);
        $order = $this->order(['discount_amount' => -0.01, 'base_discount_amount' => -0.02]);
        $items = $this->documentItems(CreditmemoItem::class, [10 => [1.0]]);
        $data = ['order_id' => 1, 'discount_amount' => 0.0, 'base_discount_amount' => -0.01];

        $lines = $this->service->getLines($this->document(Creditmemo::class, $order, $items, $data));

        $this->assertSame([0.0], $this->amounts($lines));
        $this->assertSame([0.01], $this->baseAmounts($lines));
    }

    public function testFreeShippingLineIsSplitByTheDocumentShippingShare(): void
    {
        $this->repository->method('getOrderLines')->willReturn([$this->line('free_shipping', 7, 4.0, null, 8.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => -4.0, 'base_discount_amount' => -8.0, 'shipping_amount' => '12.0000']);
        $data = [
            'order_id' => 1,
            'discount_amount' => -1.33,
            'base_discount_amount' => -2.67,
            'shipping_amount' => '4.0000',
        ];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, [], $data));

        $this->assertSame([1.33], $this->splits[0]['amounts']);
        $this->assertSame([2.67], $this->splits[0]['base']);
        $this->assertSame([1.33], $this->amounts($lines));
        $this->assertSame([2.67], $this->baseAmounts($lines));
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function shippingThresholdProvider(): array
    {
        return [
            'invoice without shipping of a shipped order' => ['10.0000', '0.0000', 0],
            'invoice with epsilon shipping of a shipped order' => ['10.0000', '0.00001', 0],
            'order with epsilon shipping keeps the free line' => ['0.00001', '0.00001', 1],
        ];
    }

    /**
     * @dataProvider shippingThresholdProvider
     */
    public function testZeroFreeShippingLineOnInvoicesFollowsTheShippingAmounts(
        string $orderShipping,
        string $docShipping,
        int $expectedCount
    ): void {
        $this->repository->method('getOrderLines')->willReturn([$this->line('free_shipping', 7, 0.0)]);
        $this->repository->method('getItemAmounts')->willReturn([]);
        $order = $this->order(['discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => $orderShipping]);
        $data = ['order_id' => 1, 'discount_amount' => 0.0, 'base_discount_amount' => 0.0, 'shipping_amount' => $docShipping];

        $lines = $this->service->getLines($this->document(Invoice::class, $order, [], $data));

        $this->assertCount($expectedCount, $lines);
    }
}
