<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use Psr\Log\LoggerInterface;

/**
 * Snapshots the per-rule discount amounts (order level and per order item) when an order is placed.
 *
 * Never lets a failure reach checkout: the order is then placed without lines and falls back to the core row.
 */
class PersistDiscountLines implements ObserverInterface
{
    private const ROUNDING_STEP = 0.01;

    /**
     * @param ConfigInterface $config
     * @param QuoteDiscountLinesInterface $quoteDiscountLines
     * @param DiscountLineRepositoryInterface $repository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly QuoteDiscountLinesInterface $quoteDiscountLines,
        private readonly DiscountLineRepositoryInterface $repository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Order $order */
            $order = $observer->getEvent()->getData('order');
            /** @var Quote $quote */
            $quote = $observer->getEvent()->getData('quote');
            if (!$this->config->isEnabled((int)$order->getStoreId())) {
                return;
            }
            $lines = $this->quoteDiscountLines->getLines($quote);
            if ($lines === []) {
                $lines = $this->genericLine($order);
            }
            if ($lines === []) {
                return;
            }

            $rows = $this->buildRows($lines, $this->itemRows($order, $quote, $lines));
            $this->repository->saveForOrder((int)$order->getEntityId(), $rows);
        } catch (\Throwable $e) {
            $this->logger->error('Discount lines could not be stored: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * One plain "Discount" line for a discount no rule could be credited with.
     *
     * The order then never shows the core row, whose label carries the rule description.
     *
     * @param Order $order
     * @return DiscountLineInterface[]
     */
    private function genericLine(Order $order): array
    {
        $amount = round(abs((float)$order->getDiscountAmount()), 2);
        $baseAmount = round(abs((float)$order->getBaseDiscountAmount()), 2);
        if ($amount <= 0.0 && $baseAmount <= 0.0) {
            return [];
        }

        return [
            new DiscountLine(
                DiscountLineInterface::KIND_AUTOMATIC,
                null,
                null,
                (string)__('Discount'),
                $amount,
                $baseAmount,
                0
            ),
        ];
    }

    /**
     * Order-level rows first (the authority for totals), then the item rows.
     *
     * @param DiscountLineInterface[] $lines
     * @param array<int, array<int, array{0: float, 1: float}>> $itemAmounts order item id => rule id => [amount, base]
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(array $lines, array $itemAmounts): array
    {
        $rows = [];
        $byRule = [];
        foreach ($lines as $line) {
            $rows[] = $this->row(null, $line, $line->getAmount(), $line->getBaseAmount());
            // Item rows only for money lines: a gift line (amount 0) lives on the order level only.
            if (
                $line->getRuleId() !== null
                && $line->getKind() !== DiscountLineInterface::KIND_FREE_SHIPPING
                && ($line->getAmount() > 0.0 || $line->getBaseAmount() > 0.0)
            ) {
                $byRule[$line->getRuleId()] = $line;
            }
        }
        foreach ($itemAmounts as $orderItemId => $perRule) {
            foreach ($perRule as $ruleId => [$amount, $baseAmount]) {
                if (isset($byRule[$ruleId])) {
                    $rows[] = $this->row($orderItemId, $byRule[$ruleId], $amount, $baseAmount);
                }
            }
        }

        return $rows;
    }

    /**
     * Per order item and rule amounts, reconciled to the order-level line of the rule.
     *
     * @param Order $order
     * @param Quote $quote
     * @param DiscountLineInterface[] $lines
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function itemRows(Order $order, Quote $quote, array $lines): array
    {
        $orderItemByQuoteItem = [];
        foreach ($order->getAllItems() as $orderItem) {
            $orderItemByQuoteItem[(int)$orderItem->getQuoteItemId()] = (int)$orderItem->getItemId();
        }

        $result = [];
        foreach ($quote->getAllItems() as $quoteItem) {
            $orderItemId = $orderItemByQuoteItem[(int)$quoteItem->getId()] ?? null;
            $extension = $quoteItem->getExtensionAttributes();
            // The extension interface is generated at runtime and unknown to static analysis.
            // @phpstan-ignore-next-line
            $discounts = $orderItemId !== null && $extension !== null ? $extension->getDiscounts() : null;
            if (!$discounts) {
                continue;
            }
            /** @var RuleDiscountInterface $discount */
            foreach ($discounts as $discount) {
                $data = $discount->getDiscountData();
                $ruleId = (int)$discount->getRuleID();
                $result[$orderItemId][$ruleId] ??= [0.0, 0.0];
                $result[$orderItemId][$ruleId][0] += abs((float)$data->getAmount());
                $result[$orderItemId][$ruleId][1] += abs((float)$data->getBaseAmount());
            }
        }

        foreach ($result as $orderItemId => $perRule) {
            foreach ($perRule as $ruleId => [$amount, $baseAmount]) {
                $result[$orderItemId][$ruleId] = [round($amount, 2), round($baseAmount, 2)];
            }
        }

        return $this->reconcile($result, $lines);
    }

    /**
     * Let the largest item row of a rule absorb a rounding difference to the order-level line.
     *
     * A larger gap (e.g. the shipping part of a rule) is no rounding and stays untouched.
     *
     * @param array<int, array<int, array{0: float, 1: float}>> $itemAmounts
     * @param DiscountLineInterface[] $lines
     * @return array<int, array<int, array{0: float, 1: float}>>
     */
    private function reconcile(array $itemAmounts, array $lines): array
    {
        foreach ($lines as $line) {
            $ruleId = $line->getRuleId();
            if ($ruleId === null || $line->getKind() === DiscountLineInterface::KIND_FREE_SHIPPING) {
                continue;
            }
            $sum = 0.0;
            $baseSum = 0.0;
            $count = 0;
            $largest = null;
            foreach ($itemAmounts as $orderItemId => $perRule) {
                if (!isset($perRule[$ruleId])) {
                    continue;
                }
                $count++;
                $sum += $perRule[$ruleId][0];
                $baseSum += $perRule[$ruleId][1];
                if ($largest === null || $perRule[$ruleId][0] > $itemAmounts[$largest][$ruleId][0]) {
                    $largest = $orderItemId;
                }
            }
            if ($largest === null) {
                continue;
            }
            $diff = round($line->getAmount() - $sum, 2);
            $baseDiff = round($line->getBaseAmount() - $baseSum, 2);
            $cap = self::ROUNDING_STEP * $count + 1e-9;
            if (($diff !== 0.0 || $baseDiff !== 0.0) && abs($diff) <= $cap && abs($baseDiff) <= $cap) {
                $itemAmounts[$largest][$ruleId] = [
                    round($itemAmounts[$largest][$ruleId][0] + $diff, 2),
                    round($itemAmounts[$largest][$ruleId][1] + $baseDiff, 2),
                ];
            }
        }

        return $itemAmounts;
    }

    /**
     * Build a storage row.
     *
     * @param int|null $orderItemId
     * @param DiscountLineInterface $line
     * @param float $amount
     * @param float $baseAmount
     * @return array<string, mixed>
     */
    private function row(?int $orderItemId, DiscountLineInterface $line, float $amount, float $baseAmount): array
    {
        return [
            'order_item_id' => $orderItemId,
            'rule_id' => $line->getRuleId(),
            'kind' => $line->getKind(),
            'code' => $line->getCode(),
            'label' => $line->getLabel(),
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'sort_order' => $line->getSortOrder(),
        ];
    }
}
