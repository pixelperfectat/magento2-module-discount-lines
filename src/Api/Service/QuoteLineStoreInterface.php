<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

/**
 * Keeps the discount lines computed at collect time on the quote address, so reads never recollect.
 */
interface QuoteLineStoreInterface
{
    public const DATA_KEY = 'pp_discount_lines';

    /**
     * Store the lines on the address (null when there are none). Does not save.
     *
     * @param Address $address
     * @param DiscountLineInterface[] $lines
     * @return void
     */
    public function store(Address $address, array $lines): void;

    /**
     * Load the lines stored on the address; empty for missing or invalid data.
     *
     * @param Address $address
     * @return DiscountLineInterface[]
     */
    public function load(Address $address): array;
}
