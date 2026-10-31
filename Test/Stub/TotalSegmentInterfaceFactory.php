<?php

declare(strict_types=1);

namespace Magento\Quote\Api\Data;

/**
 * Test stand-in for the auto-generated factory, which does not exist in a standalone checkout.
 */
class TotalSegmentInterfaceFactory
{
    /**
     * @param \Closure(): TotalSegmentInterface $creator
     */
    public function __construct(private readonly \Closure $creator)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return TotalSegmentInterface
     */
    public function create(array $data = []): TotalSegmentInterface
    {
        return ($this->creator)();
    }
}
