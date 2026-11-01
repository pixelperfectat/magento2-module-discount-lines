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

    private function orderItem(int|string $itemId, int $quoteItemId): OrderItem
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
    private function event(
        array $orderItems,
        array $quoteItems,
        float $discount = 0.0,
        ?float $baseDiscount = null
    ): Observer {
        $order = $this->createMock(Order::class);
        $order->method('getDiscountAmount')->willReturn(-$discount);
        $order->method('getBaseDiscountAmount')->willReturn(-($baseDiscount ?? $discount));
        $order->method('getEntityId')->willReturn(500);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getAllItems')->willReturn($orderItems);
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($quoteItems);

        return new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]);
    }

    private function line(
        string $kind,
        ?int $ruleId,
        ?string $code,
        string $label,
        float $amount,
        int $sort,
        ?float $baseAmount = null
    ): DiscountLine {
        return new DiscountLine($kind, $ruleId, $code, $label, $amount, $baseAmount ?? $amount, $sort);
    }

    /**
     * Run the observer and return the rows handed to the repository.
     *
     * @return array<int, array<string, mixed>>
     */
    private function persist(Observer $event): array
    {
        $captured = [];
        $this->repository->expects($this->once())->method('saveForOrder')->willReturnCallback(
            function (int $id, array $rows) use (&$captured): void {
                $captured = $rows;
            }
        );
        $this->observer->execute($event);

        return $captured;
    }

    /**
     * Item rows as "order item:rule" => [amount, base amount].
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array{mixed, mixed}>
     */
    private function itemRowAmounts(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ($row['order_item_id'] !== null) {
                $result[$row['order_item_id'] . ':' . $row['rule_id']] = [$row['amount'], $row['base_amount']];
            }
        }

        return $result;
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

    public function testGiftLineIsStoredOnOrderLevelOnly(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_GIFT, 9, 'FREEBIE', 'FREEBIE', 0.0, 0),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11)],
            [$this->quoteItem(11, [$this->discount(9, 0.0, 0.0)])]
        );

        $this->repository->expects($this->once())->method('saveForOrder')->with(
            500,
            [[
                'order_item_id' => null,
                'rule_id' => 9,
                'kind' => 'gift',
                'code' => 'FREEBIE',
                'label' => 'FREEBIE',
                'amount' => 0.0,
                'base_amount' => 0.0,
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

    public function testUnattributableDiscountedOrderStoresOneGenericLine(): void
    {
        $this->quoteLines->method('getLines')->willReturn([]);
        $captured = null;
        $this->repository->expects($this->once())->method('saveForOrder')->willReturnCallback(
            function (int $id, array $rows) use (&$captured): void {
                $captured = [$id, $rows];
            }
        );

        $this->observer->execute($this->event([$this->orderItem(901, 11)], [], 7.5));

        $this->assertSame(
            [500, [[
                'order_item_id' => null,
                'rule_id' => null,
                'kind' => 'automatic',
                'code' => null,
                'label' => 'Discount',
                'amount' => 7.5,
                'base_amount' => 7.5,
                'sort_order' => 0,
            ]]],
            $captured
        );
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

    public function testFailureIsLoggedWithItsMessageAndException(): void
    {
        $exception = new \RuntimeException('boom');
        $this->quoteLines->method('getLines')->willThrowException($exception);
        $this->logger->expects($this->once())->method('error')
            ->with('Discount lines could not be stored: boom', ['exception' => $exception]);

        $this->observer->execute($this->event([], []));
    }

    public function testGenericLineCarriesTheOrderDiscountRoundedToCents(): void
    {
        $this->quoteLines->method('getLines')->willReturn([]);

        $rows = $this->persist($this->event([], [], 7.4449, 14.8849));

        $this->assertSame(7.44, $rows[0]['amount']);
        $this->assertSame(14.88, $rows[0]['base_amount']);
    }

    public function testGenericLineIsStoredForADiscountInBaseCurrencyOnly(): void
    {
        $this->quoteLines->method('getLines')->willReturn([]);

        $rows = $this->persist($this->event([], [], 0.0, 2.0));

        $this->assertCount(1, $rows);
        $this->assertSame(0.0, $rows[0]['amount']);
        $this->assertSame(2.0, $rows[0]['base_amount']);
    }

    public function testRuleLineWithBaseMoneyOnlyStillGetsItemRows(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 0.0, 0, 0.01),
        ]);
        $event = $this->event([$this->orderItem(901, 11)], [$this->quoteItem(11, [$this->discount(1, 0.0, 0.01)])]);

        $this->assertSame(['901:1' => [0.0, 0.01]], $this->itemRowAmounts($this->persist($event)));
    }

    public function testItemRowsUseIntegerOrderItemIdsAndSkipQuoteItemsWithoutDiscountsOrOrderItem(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 2.0, 0, 4.0),
        ]);
        $event = $this->event(
            [$this->orderItem('900', 10), $this->orderItem('901', 11)],
            [
                $this->quoteItem(10, []),
                $this->quoteItem(99, [$this->discount(1, 5.0, 10.0)]),
                $this->quoteItem(11, [$this->discount(1, 2.0, 4.0)]),
            ]
        );

        $rows = $this->persist($event);

        $this->assertSame(901, $rows[1]['order_item_id']);
        $this->assertSame(['901:1' => [2.0, 4.0]], $this->itemRowAmounts($rows));
    }

    public function testSeveralDiscountsOfOneRuleOnAnItemAddUp(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 1.5, 0, 3.0),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11)],
            [$this->quoteItem(11, [$this->discount(1, -1.0, -2.0), $this->discount(1, -0.5, -1.0)])]
        );

        $this->assertSame(['901:1' => [1.5, 3.0]], $this->itemRowAmounts($this->persist($event)));
    }

    public function testItemAmountsAreRoundedToCents(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 0.83, 0, 1.67),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11), $this->orderItem(902, 12)],
            [
                $this->quoteItem(11, [$this->discount(1, 0.3333, 0.6667)]),
                $this->quoteItem(12, [$this->discount(1, 0.5, 1.0)]),
            ]
        );

        $this->assertSame(
            ['901:1' => [0.33, 0.67], '902:1' => [0.5, 1.0]],
            $this->itemRowAmounts($this->persist($event))
        );
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function subCentRemainderProvider(): array
    {
        return [
            'order currency' => [0.3349, 0.66],
            'base currency' => [0.33, 0.6649],
        ];
    }

    /**
     * @dataProvider subCentRemainderProvider
     */
    public function testRemainderBelowHalfACentIsNotAbsorbed(float $lineAmount, float $lineBase): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', $lineAmount, 0, $lineBase),
        ]);
        $event = $this->event([$this->orderItem(901, 11)], [$this->quoteItem(11, [$this->discount(1, 0.33, 0.66)])]);

        $this->assertSame(['901:1' => [0.33, 0.66]], $this->itemRowAmounts($this->persist($event)));
    }

    public function testFirstLargestItemRowOfTheRuleAbsorbsTheCentDifference(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, 3, 'SHIPFREE', 'SHIPFREE', 5.0, 0),
            $this->line(DiscountLineInterface::KIND_AUTOMATIC, 5, null, 'Shipping rule', 1.0, 1),
            $this->line(DiscountLineInterface::KIND_COUPON, 2, 'CODE-B', 'CODE-B', 1.11, 2, 2.22),
        ]);
        $event = $this->event(
            [
                $this->orderItem(900, 10),
                $this->orderItem(901, 11),
                $this->orderItem(902, 12),
                $this->orderItem(903, 13),
                $this->orderItem(904, 14),
            ],
            [
                $this->quoteItem(10, [$this->discount(7, 1.0, 2.0)]),
                $this->quoteItem(11, [$this->discount(2, 0.30, 0.60)]),
                $this->quoteItem(12, [$this->discount(2, 0.35, 0.70)]),
                $this->quoteItem(13, [$this->discount(2, 0.35, 0.70)]),
                $this->quoteItem(14, [$this->discount(2, 0.10, 0.20)]),
            ]
        );

        $this->assertSame(
            [
                '901:2' => [0.30, 0.60],
                '902:2' => [0.36, 0.72],
                '903:2' => [0.35, 0.70],
                '904:2' => [0.10, 0.20],
            ],
            $this->itemRowAmounts($this->persist($event))
        );
    }

    public function testCentDifferenceOfExactlyOneStepPerRowIsAbsorbed(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 0.34, 0, 0.67),
        ]);
        $event = $this->event([$this->orderItem(901, 11)], [$this->quoteItem(11, [$this->discount(1, 0.33, 0.66)])]);

        $this->assertSame(['901:1' => [0.34, 0.67]], $this->itemRowAmounts($this->persist($event)));
    }

    /**
     * @return array<string, array{float, float, float, float}>
     */
    public static function largeGapProvider(): array
    {
        return [
            'gap in base currency' => [1.0, 3.0, 0.99, 2.0],
            'gap in order currency' => [3.0, 2.0, 2.0, 1.99],
        ];
    }

    /**
     * A gap beyond rounding in either currency (e.g. the shipping part of a rule) leaves the item rows untouched.
     *
     * @dataProvider largeGapProvider
     */
    public function testGapBeyondRoundingInEitherCurrencyIsNotAbsorbed(
        float $lineAmount,
        float $lineBase,
        float $itemAmount,
        float $itemBase
    ): void {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', $lineAmount, 0, $lineBase),
        ]);
        $event = $this->event(
            [$this->orderItem(901, 11)],
            [$this->quoteItem(11, [$this->discount(1, $itemAmount, $itemBase)])]
        );

        $this->assertSame(['901:1' => [$itemAmount, $itemBase]], $this->itemRowAmounts($this->persist($event)));
    }

    public function testFreeShippingLineOfARuleDoesNotReconcileTheRuleItemRows(): void
    {
        $this->quoteLines->method('getLines')->willReturn([
            $this->line(DiscountLineInterface::KIND_COUPON, 1, 'CODE-A', 'CODE-A', 2.0, 0, 4.0),
            $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, 1, 'CODE-A', 'CODE-A', 2.01, 1, 4.01),
        ]);
        $event = $this->event([$this->orderItem(901, 11)], [$this->quoteItem(11, [$this->discount(1, 2.0, 4.0)])]);

        $this->assertSame(['901:1' => [2.0, 4.0]], $this->itemRowAmounts($this->persist($event)));
    }
}
