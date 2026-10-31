<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;

class QuoteDiscountLines implements QuoteDiscountLinesInterface
{
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
     * Make the item-discount lines add up to the address discount to the cent; the largest line absorbs rounding.
     *
     * @param DiscountLineInterface[] $lines
     * @param float $total
     * @param float $baseTotal
     * @return DiscountLineInterface[]
     */
    private function reconcile(array $lines, float $total, float $baseTotal): array
    {
        $largest = null;
        $sum = 0.0;
        $baseSum = 0.0;
        foreach ($lines as $position => $line) {
            if ($line->getKind() === DiscountLineInterface::KIND_FREE_SHIPPING) {
                continue;
            }
            $sum += $line->getAmount();
            $baseSum += $line->getBaseAmount();
            if ($largest === null || $line->getAmount() > $lines[$largest]->getAmount()) {
                $largest = $position;
            }
        }
        if ($largest === null) {
            return $lines;
        }

        $diff = round($total - $sum, 2);
        $baseDiff = round($baseTotal - $baseSum, 2);
        if ($diff !== 0.0 || $baseDiff !== 0.0) {
            $lines[$largest] = $lines[$largest]->withAmounts(
                round($lines[$largest]->getAmount() + $diff, 2),
                round($lines[$largest]->getBaseAmount() + $baseDiff, 2)
            );
        }

        return $lines;
    }
}
