<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\ProportionalSplitterInterface;

class ProportionalSplitter implements ProportionalSplitterInterface
{
    /**
     * @inheritDoc
     */
    public function split(array $lines, float $targetTotal, float $baseTargetTotal): array
    {
        if ($lines === []) {
            return [];
        }

        $lines = array_values($lines);
        $amounts = [];
        $baseAmounts = [];
        foreach ($lines as $index => $line) {
            $amounts[$index] = round($line->getAmount(), 2);
            $baseAmounts[$index] = round($line->getBaseAmount(), 2);
        }

        $largest = $this->largestIndex($amounts);
        $amounts[$largest] = max(0.0, round($amounts[$largest] + $targetTotal - array_sum($amounts), 2));
        $largestBase = $this->largestIndex($baseAmounts);
        $baseAmounts[$largestBase] = max(
            0.0,
            round($baseAmounts[$largestBase] + $baseTargetTotal - array_sum($baseAmounts), 2)
        );

        $result = [];
        foreach ($lines as $index => $line) {
            $result[] = $line->withAmounts($amounts[$index], $baseAmounts[$index]);
        }

        return $result;
    }

    /**
     * Index of the largest value, the first one wins a tie.
     *
     * @param float[] $values
     * @return int
     */
    private function largestIndex(array $values): int
    {
        $largest = 0;
        foreach ($values as $index => $value) {
            if ($value > $values[$largest]) {
                $largest = $index;
            }
        }

        return $largest;
    }
}
