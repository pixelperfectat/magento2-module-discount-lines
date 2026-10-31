<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface QuoteDiscountLinesInterface
{
    /**
     * Get the discount lines of a collected quote; empty when the module is disabled or there is no discount.
     *
     * @param Quote $quote
     * @return DiscountLineInterface[]
     */
    public function getLines(Quote $quote): array;
}
