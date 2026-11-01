<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Exception\UnattributableDiscountException;
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
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        return $this->compute($address, $quote);
    }

    /**
     * @inheritDoc
     */
    public function getLinesForAddress(Address $address): array
    {
        return $this->compute($address, $address->getQuote());
    }

    /**
     * Resolve and reconcile the lines of an address.
     *
     * @param Address $address
     * @param Quote $quote
     * @return DiscountLineInterface[]
     */
    private function compute(Address $address, Quote $quote): array
    {
        if (!$this->config->isEnabled((int)$quote->getStoreId())) {
            return [];
        }

        try {
            $lines = $this->resolver->resolve($address, $quote);
        } catch (UnattributableDiscountException) {
            return [];
        }
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
     * A rounding difference (at most 0.01 per line) goes to the largest line; a bigger positive remainder (in both
     * currencies) that no rule accounts for becomes its own generic line instead of inflating a rule's amount. Any
     * other gap (lines above the total, diff and base diff disagreeing) yields no lines, so the core row is shown.
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

            return $lines;
        }
        if ($diff > $cap && $baseDiff > $cap) {
            $lines[] = new DiscountLine(
                DiscountLineInterface::KIND_AUTOMATIC,
                null,
                null,
                (string)__('Discount'),
                $diff,
                $baseDiff
            );

            return $lines;
        }

        // Overshoot, opposite signs or only one currency beyond the cap: no trustworthy lines, core row instead.
        return [];
    }
}
