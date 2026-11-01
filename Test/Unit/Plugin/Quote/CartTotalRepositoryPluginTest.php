<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Plugin\Quote;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtension;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalSegmentExtensionInterface;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Api\Data\TotalSegmentInterfaceFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteLineStoreInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Plugin\Quote\CartTotalRepositoryPlugin;
use Psr\Log\LoggerInterface;

if (!interface_exists(TotalSegmentExtensionInterface::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtensionInterface.php';
}
if (!class_exists(TotalSegmentExtension::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtension.php';
}
if (!class_exists(TotalSegmentExtensionFactory::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentExtensionFactory.php';
}
if (!class_exists(TotalSegmentInterfaceFactory::class)) {
    require_once __DIR__ . '/../../../Stub/TotalSegmentInterfaceFactory.php';
}

class CartTotalRepositoryPluginTest extends TestCase
{
    private const RULE_DESCRIPTION = 'INTERNAL description - must never show to customers';

    private ConfigInterface&MockObject $config;
    private QuoteLineStoreInterface&MockObject $store;
    private LoggerInterface&MockObject $logger;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private TotalSegmentInterfaceFactory $segmentFactory;
    /** @var TotalSegmentInterface[] */
    private array $created = [];
    private Quote&MockObject $quote;
    private CartTotalRepositoryPlugin $plugin;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->store = $this->createMock(QuoteLineStoreInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $address = $this->createMock(Address::class);
        $this->quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getBillingAddress', 'getStoreId', 'collectTotals'])
            ->getMock();
        $this->quote->method('isVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($address);
        $this->quote->method('getBillingAddress')->willReturn($address);
        $this->quote->method('getStoreId')->willReturn(1);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($this->quote);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn(
            ['subtotal' => 10, 'discount' => 20, 'shipping' => 30, 'tax' => 40, 'grand_total' => 100]
        );
        $this->segmentFactory = new TotalSegmentInterfaceFactory(function (): TotalSegmentInterface {
            $segment = $this->segment(0.0);
            $segment->expects($this->once())->method('setCode')->with('discount');
            $segment->expects($this->once())->method('setValue')->with(0.0);
            $this->created[] = $segment;

            return $segment;
        });
        $this->plugin = new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            $this->store,
            new TotalSegmentExtensionFactory(),
            $this->logger,
            $this->segmentFactory,
            $this->scopeConfig
        );
    }

    private function segment(float $value): TotalSegmentInterface&MockObject
    {
        $holder = new \stdClass();
        $holder->extension = null;
        $holder->title = 'Discount (' . self::RULE_DESCRIPTION . ')';
        $segment = $this->createMock(TotalSegmentInterface::class);
        $segment->method('getValue')->willReturn($value);
        $segment->method('getTitle')->willReturnCallback(static fn() => $holder->title);
        $segment->method('setTitle')->willReturnCallback(
            static function ($title) use ($holder, $segment) {
                $holder->title = $title;

                return $segment;
            }
        );
        $segment->method('getExtensionAttributes')->willReturnCallback(static fn() => $holder->extension);
        $segment->method('setExtensionAttributes')->willReturnCallback(
            static function ($extension) use ($holder, $segment) {
                $holder->extension = $extension;

                return $segment;
            }
        );

        return $segment;
    }

    /**
     * @param array<string, TotalSegmentInterface> $segments
     */
    private function totals(array $segments): TotalsInterface&MockObject
    {
        $holder = new \stdClass();
        $holder->segments = $segments;
        $totals = $this->createMock(TotalsInterface::class);
        $totals->method('getTotalSegments')->willReturnCallback(static fn() => $holder->segments);
        $totals->method('setTotalSegments')->willReturnCallback(
            static function (array $new) use ($holder, $totals) {
                $holder->segments = $new;

                return $totals;
            }
        );

        return $totals;
    }

    private function process(TotalsInterface $totals): TotalsInterface
    {
        return $this->plugin->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7);
    }

    /**
     * @return DiscountLine[]
     */
    private function twoLines(): array
    {
        return [
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.33),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.23),
        ];
    }

    public function testStoredLinesAreExposedOnDiscountSegmentOnly(): void
    {
        $discount = $this->segment(-5.56);
        $subtotal = $this->segment(100.0);
        $lines = $this->twoLines();
        $this->store->method('load')->willReturn($lines);

        $this->process($this->totals(['subtotal' => $subtotal, 'discount' => $discount]));

        $extension = $discount->getExtensionAttributes();
        $this->assertInstanceOf(TotalSegmentExtensionInterface::class, $extension);
        $this->assertSame($lines, $extension->getDiscountLines());
        $this->assertNull($subtotal->getExtensionAttributes());
    }

    public function testLinesSumToSourceDiscount(): void
    {
        $discount = $this->segment(-5.56);
        $this->store->method('load')->willReturn($this->twoLines());

        $this->process($this->totals(['discount' => $discount]));

        $sum = array_sum(array_map(
            static fn($line) => $line->getAmount(),
            $discount->getExtensionAttributes()->getDiscountLines()
        ));
        $this->assertEqualsWithDelta(abs($discount->getValue()), $sum, 0.00001);
    }

    public function testSegmentTitleNeverContainsRuleDescription(): void
    {
        $discount = $this->segment(-5.56);
        $this->store->method('load')->willReturn($this->twoLines());

        $this->process($this->totals(['discount' => $discount]));

        $this->assertSame('Discount (CODE, Sale)', $discount->getTitle());
        $this->assertStringNotContainsString(self::RULE_DESCRIPTION, $discount->getTitle());
    }

    public function testNoCollectTotalsOnRead(): void
    {
        $this->quote->expects($this->never())->method('collectTotals');
        $this->store->method('load')->willReturn($this->twoLines());

        $this->process($this->totals(['discount' => $this->segment(-5.56)]));
    }

    public function testDisabledStoreDoesNothing(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(false);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($this->quote);
        $plugin = new CartTotalRepositoryPlugin(
            $repository,
            $config,
            $this->store,
            new TotalSegmentExtensionFactory(),
            $this->logger,
            $this->segmentFactory,
            $this->scopeConfig
        );
        $this->store->expects($this->never())->method('load');
        $this->quote->expects($this->never())->method('collectTotals');
        $discount = $this->segment(-5.56);

        $plugin->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['discount' => $discount]),
            7
        );

        $this->assertNull($discount->getExtensionAttributes());
        $this->assertStringContainsString(self::RULE_DESCRIPTION, $discount->getTitle());
    }

    public function testLinesNotSummingToTheSegmentAreIgnored(): void
    {
        $discount = $this->segment(-5.0);
        // lines of 5.56 against a discount segment of 5.00 (e.g. another plugin changed the discount afterwards)
        $this->store->method('load')->willReturn($this->twoLines());

        $this->process($this->totals(['discount' => $discount]));

        $this->assertNull($discount->getExtensionAttributes());
        $this->assertSame('Discount', $discount->getTitle());
    }

    public function testTitleWithoutStoredLinesIsPlainDiscount(): void
    {
        $discount = $this->segment(-5.56);
        $this->store->method('load')->willReturn([]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertNull($discount->getExtensionAttributes());
        $this->assertSame('Discount', $discount->getTitle());
        $this->assertStringNotContainsString(self::RULE_DESCRIPTION, $discount->getTitle());
    }

    public function testGiftOnlyQuoteGetsZeroDiscountSegmentWithLines(): void
    {
        $gift = [new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0)];
        $this->store->method('load')->willReturn($gift);
        $totals = $this->totals([
            'subtotal' => $this->segment(100.0),
            'shipping' => $this->segment(5.0),
            'grand_total' => $this->segment(105.0),
        ]);

        $this->process($totals);

        $segments = $totals->getTotalSegments();
        $this->assertSame(['subtotal', 'discount', 'shipping', 'grand_total'], array_keys($segments));
        $this->assertSame($this->created[0], $segments['discount']);
        $this->assertSame($gift, $segments['discount']->getExtensionAttributes()->getDiscountLines());
        $this->assertSame('Discount (FREEBIE)', $segments['discount']->getTitle());
    }

    public function testMissingSegmentFallsBackToBeforeShippingWithoutSortConfig(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($this->quote);
        $plugin = new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            $this->store,
            new TotalSegmentExtensionFactory(),
            $this->logger,
            $this->segmentFactory,
            $scopeConfig
        );
        $this->store->method('load')->willReturn([new DiscountLine('free_shipping', 3, 'SHIP', 'SHIP', 0.0, 0.0)]);
        $totals = $this->totals(['subtotal' => $this->segment(1.0), 'shipping' => $this->segment(0.0)]);

        $plugin->afterGet($this->createMock(CartTotalRepositoryInterface::class), $totals, 7);

        $this->assertSame(['subtotal', 'discount', 'shipping'], array_keys($totals->getTotalSegments()));
    }

    public function testNoInsertWhenAMoneyLineExistsWithZeroDiscount(): void
    {
        $this->store->method('load')->willReturn([
            new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0),
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 2.0, 2.0),
        ]);
        $totals = $this->totals(['subtotal' => $this->segment(100.0)]);

        $this->process($totals);

        $this->assertSame(['subtotal'], array_keys($totals->getTotalSegments()));
        $this->assertSame([], $this->created);
    }

    public function testNoSegmentAddedWithoutStoredLines(): void
    {
        $this->store->method('load')->willReturn([]);
        $totals = $this->totals(['subtotal' => $this->segment(100.0)]);

        $this->process($totals);

        $this->assertSame(['subtotal'], array_keys($totals->getTotalSegments()));
        $this->assertSame([], $this->created);
    }

    public function testNoChangeWithoutDiscountSegment(): void
    {
        $subtotal = $this->segment(100.0);
        $this->store->method('load')->willReturn([]);

        $this->process($this->totals(['subtotal' => $subtotal]));

        $this->assertNull($subtotal->getExtensionAttributes());
    }

    public function testKeepsExistingExtensionAttributes(): void
    {
        $discount = $this->segment(-5.56);
        $existing = new TotalSegmentExtension();
        $discount->setExtensionAttributes($existing);
        $this->store->method('load')->willReturn($this->twoLines());

        $this->process($this->totals(['discount' => $discount]));

        $this->assertSame($existing, $discount->getExtensionAttributes());
        $this->assertCount(2, $existing->getDiscountLines());
    }

    public function testFailureIsLoggedAndTotalsAreReturnedUntouched(): void
    {
        $discount = $this->segment(-2.0);
        $totals = $this->totals(['discount' => $discount]);
        $this->store->method('load')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->assertSame($totals, $this->process($totals));
        $this->assertNull($discount->getExtensionAttributes());
    }

    /**
     * Plugin over a given quote and totals sort configuration.
     */
    private function pluginFor(Quote $quote, mixed $sortConfig = null): CartTotalRepositoryPlugin
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->with(7)->willReturn($quote);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with('sales/totals_sort', 'store', 1)->willReturn($sortConfig);

        return new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            $this->store,
            new TotalSegmentExtensionFactory(),
            $this->logger,
            $this->segmentFactory,
            $scopeConfig
        );
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function addressProvider(): array
    {
        return [
            'physical quote reads the shipping address' => [false, 'shipping'],
            'virtual quote reads the billing address' => [true, 'billing'],
        ];
    }

    /**
     * @dataProvider addressProvider
     */
    public function testLinesAreReadFromTheAddressThatCarriesTheTotals(bool $virtual, string $expected): void
    {
        $shipping = $this->createMock(Address::class);
        $billing = $this->createMock(Address::class);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getBillingAddress', 'getStoreId'])
            ->getMock();
        $quote->method('isVirtual')->willReturn($virtual);
        $quote->method('getShippingAddress')->willReturn($shipping);
        $quote->method('getBillingAddress')->willReturn($billing);
        $quote->method('getStoreId')->willReturn(1);
        $this->store->expects($this->once())->method('load')
            ->with($this->identicalTo($expected === 'shipping' ? $shipping : $billing))
            ->willReturn($this->twoLines());
        $discount = $this->segment(-5.56);

        $this->pluginFor($quote)->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['discount' => $discount]),
            '7'
        );

        $this->assertSame('Discount (CODE, Sale)', $discount->getTitle());
    }

    public function testFailureIsLoggedWithItsMessageAndException(): void
    {
        $exception = new \RuntimeException('boom');
        $this->store->method('load')->willThrowException($exception);
        $this->logger->expects($this->once())->method('error')
            ->with('Discount lines could not be added to the cart totals: boom', ['exception' => $exception]);

        $this->process($this->totals(['discount' => $this->segment(-2.0)]));
    }

    public function testInsertingTheMissingSegmentLogsNoError(): void
    {
        $this->store->method('load')->willReturn([new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0)]);
        $this->logger->expects($this->never())->method('error');
        $totals = $this->totals(['subtotal' => $this->segment(100.0)]);

        $this->process($totals);

        $this->assertSame(['subtotal', 'discount'], array_keys($totals->getTotalSegments()));
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function centMatchProvider(): array
    {
        return [
            'segment with a sub-cent remainder' => [3.33, -5.564],
            'lines with a sub-cent remainder' => [3.334, -5.56],
        ];
    }

    /**
     * @dataProvider centMatchProvider
     */
    public function testLinesMatchingTheSegmentToTheCentAreShown(float $couponAmount, float $segmentValue): void
    {
        $discount = $this->segment($segmentValue);
        $this->store->method('load')->willReturn([
            new DiscountLine('coupon', 1, 'CODE', 'CODE', $couponAmount, $couponAmount),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.23),
        ]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertSame('Discount (CODE, Sale)', $discount->getTitle());
    }

    /**
     * @return array<string, array{float, float, bool}>
     */
    public static function zeroLineProvider(): array
    {
        return [
            'epsilon amount counts as zero' => [0.00001, 0.0, true],
            'epsilon base amount counts as zero' => [0.0, 0.00001, true],
            'base money only is a money line' => [0.0, 2.0, false],
            'order-currency money only is a money line' => [2.0, 0.0, false],
        ];
    }

    /**
     * @dataProvider zeroLineProvider
     */
    public function testOnlyZeroLinesInBothCurrenciesGetAMissingSegment(float $amount, float $base, bool $added): void
    {
        $this->store->method('load')->willReturn([new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', $amount, $base)]);
        $totals = $this->totals(['subtotal' => $this->segment(100.0)]);

        $this->process($totals);

        $this->assertSame($added ? ['subtotal', 'discount'] : ['subtotal'], array_keys($totals->getTotalSegments()));
    }

    public function testMissingSegmentFollowsTheConfiguredTotalsOrder(): void
    {
        $this->store->method('load')->willReturn([new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0)]);
        $totals = $this->totals([
            'subtotal' => $this->segment(100.0),
            'shipping' => $this->segment(5.0),
            'tax' => $this->segment(19.0),
            'grand_total' => $this->segment(124.0),
        ]);
        $sort = ['subtotal' => '10', 'shipping' => '30', 'tax' => '35', 'discount' => '35', 'grand_total' => '100'];

        $this->pluginFor($this->quote, $sort)->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $totals,
            7
        );

        $this->assertSame(
            ['subtotal', 'shipping', 'tax', 'discount', 'grand_total'],
            array_keys($totals->getTotalSegments())
        );
    }

    public function testMissingSegmentIsAppendedWithoutSortConfigAndShipping(): void
    {
        $this->store->method('load')->willReturn([new DiscountLine('gift', 9, 'FREEBIE', 'FREEBIE', 0.0, 0.0)]);
        $totals = $this->totals(['subtotal' => $this->segment(100.0), 'grand_total' => $this->segment(100.0)]);

        $this->pluginFor($this->quote, 'not an array')->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $totals,
            7
        );

        $this->assertSame(['subtotal', 'grand_total', 'discount'], array_keys($totals->getTotalSegments()));
    }

    public function testCouponsShowTheirCodeAndEverythingElseItsLabel(): void
    {
        $discount = $this->segment(-6.0);
        $this->store->method('load')->willReturn([
            new DiscountLine('coupon', 1, 'SAVE10', 'Save ten', 1.0, 1.0),
            new DiscountLine('automatic', 2, 'AUTO', 'Sale', 2.0, 2.0),
            new DiscountLine('coupon', 3, null, 'Coupon', 3.0, 3.0),
        ]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertSame('Discount (SAVE10, Sale, Coupon)', $discount->getTitle());
    }
}
