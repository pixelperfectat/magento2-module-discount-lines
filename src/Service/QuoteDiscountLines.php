<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

class QuoteDiscountLines implements QuoteDiscountLinesInterface
{
    private const ROUNDING_STEP = 0.01;

    /**
     * @param ConfigInterface $config
     * @param DiscountLineResolverInterface $resolver
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly DiscountLineResolverInterface $resolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLines(Quote $quote): array
    {
        if (!$this->config->isEnabled((int)$quote->getStoreId())) {
            return [];
        }

        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        $lines = $this->resolver->resolve($address, $quote);
        if ($lines === []) {
            return [];
        }

        $lines = $this->reconcile(
            $lines,
            abs((float)$address->getDiscountAmount()),
            abs((float)$address->getBaseDiscountAmount())
        );

        $result = [];
        foreach ($lines as $position => $line) {
            $result[] = $line->withSortOrder($position);
        }

        return $result;
    }

    /**
     * Make ALL lines add up to the address discount to the cent.
     *
     * A rounding difference (at most 0.01 per line) goes to the largest line; a bigger positive remainder that no
     * rule accounts for becomes its own generic line instead of inflating a rule's amount.
     *
     * @param DiscountLineInterface[] $lines
     * @param float $total
     * @param float $baseTotal
     * @return DiscountLineInterface[]
     */
    private function reconcile(array $lines, float $total, float $baseTotal): array
    {
        $largest = 0;
        $sum = 0.0;
        $baseSum = 0.0;
        foreach ($lines as $position => $line) {
            $sum += $line->getAmount();
            $baseSum += $line->getBaseAmount();
            if ($line->getAmount() > $lines[$largest]->getAmount()) {
                $largest = $position;
            }
        }

        $diff = round($total - $sum, 2);
        $baseDiff = round($baseTotal - $baseSum, 2);
        if ($diff === 0.0 && $baseDiff === 0.0) {
            return $lines;
        }

        $cap = self::ROUNDING_STEP * count($lines) + 1e-9;
        if (abs($diff) <= $cap && abs($baseDiff) <= $cap) {
            $lines[$largest] = $lines[$largest]->withAmounts(
                round($lines[$largest]->getAmount() + $diff, 2),
                round($lines[$largest]->getBaseAmount() + $baseDiff, 2)
            );
        } elseif ($diff > 0.0 && $baseDiff >= 0.0) {
            $lines[] = new DiscountLine(
                DiscountLineInterface::KIND_AUTOMATIC,
                null,
                null,
                (string)__('Discount'),
                $diff,
                $baseDiff
            );
        }

        return $lines;
    }
}
