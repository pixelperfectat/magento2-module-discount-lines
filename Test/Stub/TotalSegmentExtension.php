<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

/**
 * Test stand-in for the auto-generated extension class, which does not exist in a standalone checkout.
 */
class TotalSegmentExtension implements TotalSegmentExtensionInterface
{
    /** @var array<int, mixed>|null */
    private ?array $discountLines = null;

    /**
     * @inheritDoc
     */
    public function getDiscountLines()
    {
        return $this->discountLines;
    }

    /**
     * @inheritDoc
     */
    public function setDiscountLines(array $discountLines)
    {
        $this->discountLines = $discountLines;

        return $this;
    }
}
