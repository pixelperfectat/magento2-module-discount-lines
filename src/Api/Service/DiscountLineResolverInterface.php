<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface DiscountLineResolverInterface
{
    /**
     * Resolve discount lines for a collected quote address.
     *
     * @param Address $address
     * @param Quote $quote
     * @return DiscountLineInterface[]
     */
    public function resolve(Address $address, Quote $quote): array;
}
