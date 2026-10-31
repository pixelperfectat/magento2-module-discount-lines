<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\ViewModel;

use Magento\Framework\View\Asset\Repository;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

/**
 * Data for the storefront renderers (Hyva Checkout, Hyva cart, Luma): variant, icons and normalised lines.
 */
class DiscountLines implements ArgumentInterface
{
    public const DEFAULT_ICON = 'tag';

    /**
     * @param ConfigInterface $config
     * @param Repository $assetRepository
     * @param array<string, string> $icons line kind => icon file name (without extension) in `web/images`
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly Repository $assetRepository,
        private readonly array $icons = [
            DiscountLineInterface::KIND_COUPON => 'tag',
            DiscountLineInterface::KIND_AUTOMATIC => 'percent',
            DiscountLineInterface::KIND_GIFT => 'gift',
            DiscountLineInterface::KIND_FREE_SHIPPING => 'percent',
            DiscountLineInterface::KIND_GIFT_CARD => 'gift',
        ]
    ) {
    }

    /**
     * Whether itemised discount lines are enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Get the configured display variant (A, B, C or D).
     *
     * @return string
     */
    public function getVariant(): string
    {
        return $this->config->getVariant();
    }

    /**
     * Read the discount lines of a total segment.
     *
     * Accepts the segment as an array whose `extension_attributes` is the extension object, or as a plain
     * (JSON-decoded) array. Lines are returned as contract arrays plus `removable` (true for coupons).
     *
     * @param array<string, mixed> $segment
     * @return array<int, array{
     *     kind: string,
     *     rule_id: int|null,
     *     code: string|null,
     *     label: string,
     *     amount: float,
     *     base_amount: float,
     *     removable: bool
     * }>
     */
    public function getLinesFromSegment(array $segment): array
    {
        $extension = $segment['extension_attributes'] ?? null;
        if (is_object($extension) && method_exists($extension, 'getDiscountLines')) {
            $raw = $extension->getDiscountLines();
        } elseif (is_array($extension)) {
            $raw = $extension['discount_lines'] ?? null;
        } else {
            $raw = null;
        }
        if (!is_array($raw)) {
            return [];
        }

        $lines = [];
        foreach ($raw as $line) {
            $data = $line instanceof DiscountLineInterface ? $line->toArray() : $line;
            if (is_array($data)) {
                $lines[] = $this->normalise($data);
            }
        }

        return $lines;
    }

    /**
     * Get the URL of the icon for a line kind (tag for unknown kinds).
     *
     * @param string $kind
     * @return string
     */
    public function getIconUrl(string $kind): string
    {
        $icon = $this->icons[$kind] ?? self::DEFAULT_ICON;

        return $this->assetRepository->getUrl('PixelPerfect_DiscountLines::images/' . $icon . '.svg');
    }

    /**
     * Normalise a contract array and add the `removable` flag.
     *
     * @param array<string, mixed> $data
     * @return array{
     *     kind: string,
     *     rule_id: int|null,
     *     code: string|null,
     *     label: string,
     *     amount: float,
     *     base_amount: float,
     *     removable: bool
     * }
     */
    private function normalise(array $data): array
    {
        $kind = (string)($data['kind'] ?? '');

        return [
            'kind' => $kind,
            'rule_id' => isset($data['rule_id']) ? (int)$data['rule_id'] : null,
            'code' => isset($data['code']) ? (string)$data['code'] : null,
            'label' => (string)($data['label'] ?? ''),
            'amount' => (float)($data['amount'] ?? 0.0),
            'base_amount' => (float)($data['base_amount'] ?? 0.0),
            'removable' => $kind === DiscountLineInterface::KIND_COUPON,
        ];
    }
}
