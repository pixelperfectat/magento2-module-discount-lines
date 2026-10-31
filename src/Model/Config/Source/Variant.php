<?php declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Variant implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'A', 'label' => __('A - Label with chip below')],
            ['value' => 'B', 'label' => __('B - Chip as the label')],
            ['value' => 'C', 'label' => __('C - Grouped block with "You save" total')],
            ['value' => 'D', 'label' => __('D - Quiet icon and monospace')],
        ];
    }
}
