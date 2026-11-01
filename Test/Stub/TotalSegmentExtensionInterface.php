<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

/**
 * Test stand-in for the auto-generated extension interface, which does not exist in a standalone checkout.
 */
interface TotalSegmentExtensionInterface
{
    /**
     * @return \PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface[]|null
     */
    public function getDiscountLines();

    /**
     * @param \PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface[] $discountLines
     * @return $this
     */
    public function setDiscountLines(array $discountLines);
}
