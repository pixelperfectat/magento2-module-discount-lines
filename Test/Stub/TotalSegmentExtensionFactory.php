<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

/**
 * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
 */
class TotalSegmentExtensionFactory
{
    /**
     * @param array<string, mixed> $data
     * @return TotalSegmentExtension
     */
    public function create(array $data = []): TotalSegmentExtension
    {
        return new TotalSegmentExtension();
    }
}
