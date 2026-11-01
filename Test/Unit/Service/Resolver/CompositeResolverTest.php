<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\Resolver\CompositeResolver;
use Psr\Log\LoggerInterface;

class CompositeResolverTest extends TestCase
{
    private function resolver(DiscountLine ...$lines): DiscountLineResolverInterface
    {
        $resolver = $this->createMock(DiscountLineResolverInterface::class);
        $resolver->method('resolve')->willReturn($lines);

        return $resolver;
    }

    public function testCollectsTheLinesOfAllResolversInOrder(): void
    {
        $first = new DiscountLine('coupon', 1, 'X', 'X', 2.0, 2.0);
        $second = new DiscountLine('free_shipping', 2, null, 'Free shipping', 0.0, 0.0);
        $composite = new CompositeResolver(
            $this->createMock(LoggerInterface::class),
            ['a' => $this->resolver($first), 'b' => $this->resolver($second)]
        );

        $lines = $composite->resolve($this->createMock(Address::class), $this->createMock(Quote::class));

        $this->assertSame([$first, $second], $lines);
    }

    public function testInvalidEntriesAreSkippedAndLoggedInsteadOfBreakingCheckout(): void
    {
        $line = new DiscountLine('coupon', 1, 'X', 'X', 2.0, 2.0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('"broken"'));

        $composite = new CompositeResolver($logger, ['broken' => new \stdClass(), 'ok' => $this->resolver($line)]);
        $lines = $composite->resolve($this->createMock(Address::class), $this->createMock(Quote::class));

        $this->assertSame([$line], $lines);
    }
}
