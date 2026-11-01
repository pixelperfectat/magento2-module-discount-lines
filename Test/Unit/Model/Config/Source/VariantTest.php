<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Model\Config\Source;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Model\Config\Source\Variant;

class VariantTest extends TestCase
{
    public function testOffersTheFourVariantsWithTheirLabels(): void
    {
        $options = (new Variant())->toOptionArray();

        $this->assertSame(
            [
                ['A', 'A - Label with chip below'],
                ['B', 'B - Chip as the label'],
                ['C', 'C - Grouped block with "You save" total'],
                ['D', 'D - Quiet icon and monospace'],
            ],
            array_map(static fn(array $option): array => [$option['value'], (string)$option['label']], $options)
        );
    }
}
