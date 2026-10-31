<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Plugin\Quote;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtension;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalSegmentExtensionInterface;
use Magento\Quote\Api\Data\TotalSegmentInterface;
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

class CartTotalRepositoryPluginTest extends TestCase
{
    private const RULE_DESCRIPTION = 'INTERNAL description - must never show to customers';

    private ConfigInterface&MockObject $config;
    private QuoteLineStoreInterface&MockObject $store;
    private LoggerInterface&MockObject $logger;
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
        $this->plugin = new CartTotalRepositoryPlugin(
            $repository,
            $this->config,
            $this->store,
            new TotalSegmentExtensionFactory(),
            $this->logger
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
        $totals = $this->createMock(TotalsInterface::class);
        $totals->method('getTotalSegments')->willReturn($segments);

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
            $this->logger
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

    public function testTitleWithoutStoredLinesIsPlainDiscount(): void
    {
        $discount = $this->segment(-5.56);
        $this->store->method('load')->willReturn([]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertNull($discount->getExtensionAttributes());
        $this->assertSame('Discount', $discount->getTitle());
        $this->assertStringNotContainsString(self::RULE_DESCRIPTION, $discount->getTitle());
    }

    public function testNoChangeWithoutDiscountSegment(): void
    {
        $subtotal = $this->segment(100.0);
        $this->store->expects($this->never())->method('load');

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
}
