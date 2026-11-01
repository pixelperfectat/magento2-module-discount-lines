<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
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

    /**
     * Get the discount lines of one collected quote address (the quote is taken from the address).
     *
     * Empty when the module is disabled, there is no discount, or the discount cannot be attributed to rules.
     *
     * @param Address $address
     * @return DiscountLineInterface[]
     */
    public function getLinesForAddress(Address $address): array;
}
