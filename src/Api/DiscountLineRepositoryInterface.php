<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api;

use Magento\Framework\Exception\LocalizedException;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface DiscountLineRepositoryInterface
{
    /**
     * Replace the stored lines of an order.
     *
     * Each row: order_item_id (int|null, null = order-level), rule_id (int|null), kind, code (string|null),
     * label, amount, base_amount, sort_order.
     *
     * @param int $orderId
     * @param array<int, array<string, mixed>> $rows
     * @return void
     * @throws LocalizedException
     */
    public function saveForOrder(int $orderId, array $rows): void;

    /**
     * Get the order-level lines in display order.
     *
     * @param int $orderId
     * @return DiscountLineInterface[]
     */
    public function getOrderLines(int $orderId): array;

    /**
     * Get the item-level amounts: order_item_id => rule key => amounts.
     *
     * The rule key is the rule id, or "kind:code" for lines without a rule.
     *
     * @param int $orderId
     * @return array<int, array<int|string, array{amount: float, base_amount: float}>>
     */
    public function getItemAmounts(int $orderId): array;

    /**
     * Whether lines were stored for the order.
     *
     * @param int $orderId
     * @return bool
     */
    public function hasLines(int $orderId): bool;
}
