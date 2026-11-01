<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface ProportionalSplitterInterface
{
    /**
     * Round the lines to cents and let the largest line absorb the difference to the target totals.
     *
     * @param DiscountLineInterface[] $lines
     * @param float $targetTotal Positive discount total in order currency
     * @param float $baseTargetTotal Positive discount total in base currency
     * @return DiscountLineInterface[]
     */
    public function split(array $lines, float $targetTotal, float $baseTargetTotal): array;
}
