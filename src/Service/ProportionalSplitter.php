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
        $amounts = $this->reconcile(
            array_map(static fn(DiscountLineInterface $line): float => $line->getAmount(), $lines),
            $targetTotal
        );
        $baseAmounts = $this->reconcile(
            array_map(static fn(DiscountLineInterface $line): float => $line->getBaseAmount(), $lines),
            $baseTargetTotal
        );

        $result = [];
        foreach ($lines as $index => $line) {
            $result[] = $line->withAmounts($amounts[$index], $baseAmounts[$index]);
        }

        return $result;
    }

    /**
     * Scale the values to the target, round to cents and give the rounding remainder to the largest value.
     *
     * @param float[] $values
     * @param float $target
     * @return float[]
     */
    private function reconcile(array $values, float $target): array
    {
        $target = round($target, 2);
        $sum = array_sum($values);
        if ($sum > 0.0) {
            $scaled = array_map(static fn(float $value): float => round($value * $target / $sum, 2), $values);
        } else {
            $scaled = array_fill(0, count($values), 0.0);
        }
        $largest = $this->largestIndex($sum > 0.0 ? $values : $scaled);
        $scaled[$largest] = round($scaled[$largest] + $target - array_sum($scaled), 2);

        return $scaled;
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
