<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Data;

/**
 * One itemised discount line (one sales rule, one gift, ...).
 *
 * Storefront shape (identical in REST `extension_attributes.discount_lines[]` and the cart page checkout config):
 * `kind`, `rule_id`, `label`, `amount`, `base_amount`, `sort_order`, plus `code` when the line has one (the key is
 * omitted, not null, for lines without a code). Amounts are positive floats.
 */
interface DiscountLineInterface
{
    public const KIND_COUPON = 'coupon';
    public const KIND_AUTOMATIC = 'automatic';
    public const KIND_GIFT = 'gift';
    public const KIND_FREE_SHIPPING = 'free_shipping';
    public const KIND_GIFT_CARD = 'gift_card';

    /**
     * Get the line kind (one of the KIND_* constants).
     *
     * @return string
     */
    public function getKind(): string;

    /**
     * Get the sales rule id, null for lines without a rule (gift card).
     *
     * @return int|null
     */
    public function getRuleId(): ?int;

    /**
     * Get the coupon / gift card code, null when there is none.
     *
     * @return string|null
     */
    public function getCode(): ?string;

    /**
     * Get the customer-safe label (already resolved by the label rule).
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Get the discount in order currency, positive number.
     *
     * @return float
     */
    public function getAmount(): float;

    /**
     * Get the discount in base currency, positive number.
     *
     * @return float
     */
    public function getBaseAmount(): float;

    /**
     * Get the 0-based display position.
     *
     * @return int
     */
    public function getSortOrder(): int;

    /**
     * Return a copy with other amounts (used to absorb rounding differences).
     *
     * @param float $amount
     * @param float $baseAmount
     * @return DiscountLineInterface
     */
    public function withAmounts(float $amount, float $baseAmount): DiscountLineInterface;

    /**
     * Return a copy with another sort order.
     *
     * @param int $sortOrder
     * @return DiscountLineInterface
     */
    public function withSortOrder(int $sortOrder): DiscountLineInterface;

    /**
     * Export the sales-document contract (`discount_line` row data).
     *
     * @return array{
     *     kind: string,
     *     rule_id: int|null,
     *     code: string|null,
     *     label: string,
     *     amount: float,
     *     base_amount: float
     * }
     */
    public function toArray(): array;
}
