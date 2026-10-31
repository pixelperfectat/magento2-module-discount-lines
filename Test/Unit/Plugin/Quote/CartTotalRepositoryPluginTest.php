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
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
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
    private CartRepositoryInterface&MockObject $cartRepository;
    private QuoteDiscountLinesInterface&MockObject $quoteLines;
    private LoggerInterface&MockObject $logger;
    private CartTotalRepositoryPlugin $plugin;

    protected function setUp(): void
    {
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cartRepository->method('getActive')->willReturn($this->quoteWithAddress(0.0, null));
        $this->quoteLines = $this->createMock(QuoteDiscountLinesInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new CartTotalRepositoryPlugin(
            $this->cartRepository,
            $this->quoteLines,
            new TotalSegmentExtensionFactory(),
            $this->logger
        );
    }

    /**
     * @return TotalSegmentInterface&MockObject
     */
    private function segment(float $value): TotalSegmentInterface
    {
        $holder = new \stdClass();
        $holder->extension = null;
        $segment = $this->createMock(TotalSegmentInterface::class);
        $segment->method('getValue')->willReturn($value);
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

    public function testAddsLinesToDiscountSegmentOnly(): void
    {
        $discount = $this->segment(-5.56);
        $subtotal = $this->segment(100.0);
        $lines = [
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.33),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.23),
        ];
        $this->quoteLines->method('getLines')->willReturn($lines);

        $this->process($this->totals(['subtotal' => $subtotal, 'discount' => $discount]));

        $extension = $discount->getExtensionAttributes();
        $this->assertInstanceOf(TotalSegmentExtensionInterface::class, $extension);
        $this->assertSame($lines, $extension->getDiscountLines());
        $this->assertNull($subtotal->getExtensionAttributes());
    }

    public function testLinesSumToSourceDiscount(): void
    {
        $discount = $this->segment(-5.56);
        $this->quoteLines->method('getLines')->willReturn([
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.33),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.23),
        ]);

        $this->process($this->totals(['discount' => $discount]));

        $sum = array_sum(array_map(
            static fn($line) => $line->getAmount(),
            $discount->getExtensionAttributes()->getDiscountLines()
        ));
        $this->assertEqualsWithDelta(abs($discount->getValue()), $sum, 0.00001);
    }

    /**
     * @return Quote&MockObject
     */
    private function quoteWithAddress(float $discount, ?object $extension): Quote
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes'])
            ->addMethods(['getDiscountAmount'])
            ->getMock();
        $address->method('getDiscountAmount')->willReturn($discount);
        $address->method('getExtensionAttributes')->willReturn($extension);
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getBillingAddress', 'collectTotals'])
            ->getMock();
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getBillingAddress')->willReturn($address);

        return $quote;
    }

    private function pluginFor(Quote $quote): CartTotalRepositoryPlugin
    {
        $repository = $this->createMock(CartRepositoryInterface::class);
        $repository->method('getActive')->willReturn($quote);

        return new CartTotalRepositoryPlugin(
            $repository,
            $this->quoteLines,
            new TotalSegmentExtensionFactory(),
            $this->logger
        );
    }

    public function testRecollectsWhenTheLoadedQuoteHasNoRuleBreakdown(): void
    {
        $quote = $this->quoteWithAddress(-5.0, null);
        $quote->expects($this->once())->method('collectTotals');
        $this->quoteLines->method('getLines')->willReturn([]);

        $this->pluginFor($quote)->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['discount' => $this->segment(-5.0)]),
            7
        );
    }

    public function testDoesNotRecollectWhenTheQuoteAlreadyHasTheBreakdown(): void
    {
        $extension = new class {
            /** @return array<int, object> */
            public function getDiscounts(): array
            {
                return [new \stdClass()];
            }
        };
        $quote = $this->quoteWithAddress(-5.0, $extension);
        $quote->expects($this->never())->method('collectTotals');
        $this->quoteLines->method('getLines')->willReturn([]);

        $this->pluginFor($quote)->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['discount' => $this->segment(-5.0)]),
            7
        );
    }

    public function testDoesNotRecollectWithoutDiscount(): void
    {
        $quote = $this->quoteWithAddress(0.0, null);
        $quote->expects($this->never())->method('collectTotals');
        $this->quoteLines->method('getLines')->willReturn([]);

        $this->pluginFor($quote)->afterGet(
            $this->createMock(CartTotalRepositoryInterface::class),
            $this->totals(['discount' => $this->segment(0.0)]),
            7
        );
    }

    public function testNoChangeWhenThereAreNoLines(): void
    {
        $discount = $this->segment(0.0);
        $this->quoteLines->method('getLines')->willReturn([]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertNull($discount->getExtensionAttributes());
    }

    public function testNoChangeWithoutDiscountSegment(): void
    {
        $subtotal = $this->segment(100.0);
        $this->quoteLines->expects($this->never())->method('getLines');

        $this->process($this->totals(['subtotal' => $subtotal]));

        $this->assertNull($subtotal->getExtensionAttributes());
    }

    public function testKeepsExistingExtensionAttributes(): void
    {
        $discount = $this->segment(-2.0);
        $existing = new TotalSegmentExtension();
        $discount->setExtensionAttributes($existing);
        $this->quoteLines->method('getLines')->willReturn([new DiscountLine('coupon', 1, 'C', 'C', 2.0, 2.0)]);

        $this->process($this->totals(['discount' => $discount]));

        $this->assertSame($existing, $discount->getExtensionAttributes());
        $this->assertCount(1, $existing->getDiscountLines());
    }

    public function testFailureIsLoggedAndTotalsAreReturnedUntouched(): void
    {
        $discount = $this->segment(-2.0);
        $totals = $this->totals(['discount' => $discount]);
        $this->quoteLines->method('getLines')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->once())->method('error');

        $this->assertSame($totals, $this->process($totals));
        $this->assertNull($discount->getExtensionAttributes());
    }
}
