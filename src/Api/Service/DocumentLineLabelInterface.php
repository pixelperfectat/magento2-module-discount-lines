<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface DocumentLineLabelInterface
{
    /**
     * Get the plain-text, translated label of a line for sales documents.
     *
     * @param DiscountLineInterface $line
     * @return string
     */
    public function format(DiscountLineInterface $line): string;

    /**
     * Get the signed document value: negative for discounts, zero for gifts.
     *
     * @param DiscountLineInterface $line
     * @param bool $base Use the base currency amount
     * @return float
     */
    public function value(DiscountLineInterface $line, bool $base = false): float;

    /**
     * Get the translated text printed in place of the amount: "Gift" / "Free" for zero-amount gift and free
     * shipping lines, null when the amount itself is printed.
     *
     * @param DiscountLineInterface $line
     * @return string|null
     */
    public function amountText(DiscountLineInterface $line): ?string;
}
