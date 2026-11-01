<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\ProportionalSplitter;

class ProportionalSplitterTest extends TestCase
{
    private ProportionalSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new ProportionalSplitter();
    }

    private function line(int $ruleId, float $amount): DiscountLineInterface
    {
        return new DiscountLine(DiscountLineInterface::KIND_AUTOMATIC, $ruleId, null, 'Rule ' . $ruleId, $amount, $amount);
    }

    /**
     * @param DiscountLineInterface[] $lines
     * @return float[]
     */
    private function amounts(array $lines): array
    {
        return array_map(static fn(DiscountLineInterface $line): float => $line->getAmount(), $lines);
    }

    public function testFullRefundKeepsLines(): void
    {
        $lines = [$this->line(1, 2.25), $this->line(2, 5.0)];

        $result = $this->splitter->split($lines, 7.25, 7.25);

        $this->assertSame([2.25, 5.0], $this->amounts($result));
    }

    public function testPartialRefundReconcilesToTarget(): void
    {
        $result = $this->splitter->split([$this->line(1, 3.33), $this->line(2, 6.67)], 3.34, 3.34);

        $this->assertSame(3.34, round(array_sum($this->amounts($result)), 2));
    }

    public function testLinesAreScaledDownToTarget(): void
    {
        $result = $this->splitter->split([$this->line(1, 1.0), $this->line(2, 1.0)], 0.5, 0.5);

        $this->assertSame([0.25, 0.25], $this->amounts($result));
    }

    public function testRoundingRemainderGoesToLargestLine(): void
    {
        $lines = [$this->line(1, 1 / 3), $this->line(2, 1 / 3), $this->line(3, 1 / 3)];

        $result = $this->splitter->split($lines, 1.0, 1.0);

        $this->assertSame([0.34, 0.33, 0.33], $this->amounts($result));
    }

    public function testLinesSumToSourceDiscount(): void
    {
        $lines = [$this->line(1, 1.117), $this->line(2, 2.224), $this->line(3, 0.559)];

        $result = $this->splitter->split($lines, 3.9, 3.9);

        $this->assertSame(3.9, round(array_sum($this->amounts($result)), 2));
    }

    public function testEmptyLinesStayEmpty(): void
    {
        $this->assertSame([], $this->splitter->split([], 1.0, 1.0));
    }
}
