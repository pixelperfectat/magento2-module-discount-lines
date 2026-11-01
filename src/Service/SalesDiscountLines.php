<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Sales\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\ProportionalSplitterInterface;
use PixelPerfect\DiscountLines\Api\Service\SalesDiscountLinesInterface;

class SalesDiscountLines implements SalesDiscountLinesInterface
{
    private const EPSILON = 0.00001;

    /** @var \WeakMap<AbstractModel, DiscountLineInterface[]> */
    private \WeakMap $cache;

    /**
     * @param DiscountLineRepositoryInterface $repository
     * @param ProportionalSplitterInterface $splitter
     */
    public function __construct(
        private readonly DiscountLineRepositoryInterface $repository,
        private readonly ProportionalSplitterInterface $splitter
    ) {
        $this->cache = new \WeakMap();
    }

    /**
     * @inheritDoc
     */
    public function getLines(AbstractModel $source): array
    {
        if (isset($this->cache[$source])) {
            return $this->cache[$source];
        }

        $lines = $this->load($source);
        if ($lines !== []) {
            $this->cache[$source] = $lines;
        }

        return $lines;
    }

    /**
     * Load the lines for a source.
     *
     * @param AbstractModel $source
     * @return DiscountLineInterface[]
     */
    private function load(AbstractModel $source): array
    {
        if ($source instanceof Order) {
            return $this->forOrder($source, $this->repository->getOrderLines((int)$source->getId()));
        }

        $orderId = (int)$source->getData('order_id');
        $lines = $this->repository->getOrderLines($orderId);
        if ($lines === []) {
            return [];
        }

        return $this->splitForDocument($source, $orderId, $lines);
    }

    /**
     * Stored order lines as they are when they add up to the order discount, otherwise scaled to it.
     *
     * @param Order $order
     * @param DiscountLineInterface[] $lines
     * @return DiscountLineInterface[]
     */
    private function forOrder(Order $order, array $lines): array
    {
        $target = round(abs((float)$order->getData('discount_amount')), 2);
        $baseTarget = round(abs((float)$order->getData('base_discount_amount')), 2);
        $sum = 0.0;
        $baseSum = 0.0;
        foreach ($lines as $line) {
            $sum += $line->getAmount();
            $baseSum += $line->getBaseAmount();
        }
        if (round($sum, 2) === $target && round($baseSum, 2) === $baseTarget) {
            return $lines;
        }

        return $this->scaleTo($lines, $target, $baseTarget);
    }

    /**
     * Scale lines to a discount total; no lines (core row) when there is no money to scale or nothing to scale to.
     *
     * @param DiscountLineInterface[] $lines
     * @param float $target
     * @param float $baseTarget
     * @return DiscountLineInterface[]
     */
    private function scaleTo(array $lines, float $target, float $baseTarget): array
    {
        $sum = 0.0;
        $baseSum = 0.0;
        foreach ($lines as $line) {
            $sum += $line->getAmount();
            $baseSum += $line->getBaseAmount();
        }
        $hasMoney = $sum > self::EPSILON && $baseSum > self::EPSILON;
        $hasTarget = $target > self::EPSILON && $baseTarget > self::EPSILON;
        if ($hasMoney !== $hasTarget) {
            return [];
        }

        return $this->splitter->split($lines, $target, $baseTarget);
    }

    /**
     * Split the stored order lines for an invoice or credit memo.
     *
     * @param AbstractModel $document
     * @param int $orderId
     * @param DiscountLineInterface[] $lines
     * @return DiscountLineInterface[]
     */
    private function splitForDocument(AbstractModel $document, int $orderId, array $lines): array
    {
        $order = $document->getOrder();
        $itemAmounts = $this->repository->getItemAmounts($orderId);
        $docDiscount = abs((float)$document->getData('discount_amount'));
        $docBaseDiscount = abs((float)$document->getData('base_discount_amount'));
        $share = $this->share($docDiscount, abs((float)$order->getData('discount_amount')));
        $baseShare = $this->share($docBaseDiscount, abs((float)$order->getData('base_discount_amount')));
        $perRule = $this->documentRuleAmounts($document, $itemAmounts);

        $result = [];
        foreach ($lines as $line) {
            $ruleId = $line->getRuleId();
            if ($line->getKind() === DiscountLineInterface::KIND_FREE_SHIPPING) {
                $shipping = $this->shippingLine($line, $document, $order);
                if ($shipping !== null) {
                    $result[] = $shipping;
                }
            } elseif ($ruleId !== null && $this->ruleHasItemRows($itemAmounts, $ruleId)) {
                $amounts = $perRule[$ruleId] ?? ['amount' => 0.0, 'base_amount' => 0.0];
                $split = $line->withAmounts(round($amounts['amount'], 2), round($amounts['base_amount'], 2));
                if ($split->getAmount() > 0.0 || $split->getBaseAmount() > 0.0) {
                    $result[] = $split;
                }
            } else {
                $result[] = $line->withAmounts(
                    round($line->getAmount() * $share, 2),
                    round($line->getBaseAmount() * $baseShare, 2)
                );
            }
        }

        // Zero lines (gift, free shipping) stay on orders and invoices, but not on credit memos.
        $keepZero = !$document instanceof Creditmemo;
        $result = array_filter($result, static fn(DiscountLineInterface $line): bool => (
            $line->getAmount() > 0.0
            || $line->getBaseAmount() > 0.0
            || (
                $keepZero
                && in_array(
                    $line->getKind(),
                    [DiscountLineInterface::KIND_GIFT, DiscountLineInterface::KIND_FREE_SHIPPING],
                    true
                )
            )
        ));

        return $this->scaleTo($result, $docDiscount, $docBaseDiscount);
    }

    /**
     * The part's share of the whole, capped at 1; zero when the whole is zero.
     *
     * @param float $part
     * @param float $whole
     * @return float
     */
    private function share(float $part, float $whole): float
    {
        return $whole > self::EPSILON ? min(1.0, $part / $whole) : 0.0;
    }

    /**
     * Sum the item-level rule amounts weighted by document qty / ordered qty.
     *
     * @param AbstractModel $document
     * @param array<int, array<int, array{amount: float, base_amount: float}>> $itemAmounts
     * @return array<int, array{amount: float, base_amount: float}> rule_id => amounts
     */
    private function documentRuleAmounts(AbstractModel $document, array $itemAmounts): array
    {
        $perRule = [];
        foreach ($document->getItems() as $item) {
            $orderItem = $item->getOrderItem();
            $orderItemId = $item->getOrderItemId();
            $parentId = $orderItem->getParentItemId();
            if (!isset($itemAmounts[$orderItemId]) || ($parentId !== null && isset($itemAmounts[$parentId]))) {
                continue;
            }
            $ordered = (float)$orderItem->getQtyOrdered();
            if ($ordered <= 0.0) {
                continue;
            }
            $ratio = $item->getQty() / $ordered;
            foreach ($itemAmounts[$orderItemId] as $ruleId => $amounts) {
                $perRule[$ruleId] ??= ['amount' => 0.0, 'base_amount' => 0.0];
                $perRule[$ruleId]['amount'] += $amounts['amount'] * $ratio;
                $perRule[$ruleId]['base_amount'] += $amounts['base_amount'] * $ratio;
            }
        }

        return $perRule;
    }

    /**
     * Whether any item row exists for the rule.
     *
     * @param array<int, array<int, array{amount: float, base_amount: float}>> $itemAmounts
     * @param int $ruleId
     * @return bool
     */
    private function ruleHasItemRows(array $itemAmounts, int $ruleId): bool
    {
        foreach ($itemAmounts as $rules) {
            if (isset($rules[$ruleId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scale the free shipping line by the document's share of the order shipping.
     *
     * With no order shipping the zero "Free" line stays on invoices only.
     *
     * @param DiscountLineInterface $line
     * @param AbstractModel $document
     * @param AbstractModel $order
     * @return DiscountLineInterface|null
     */
    private function shippingLine(
        DiscountLineInterface $line,
        AbstractModel $document,
        AbstractModel $order
    ): ?DiscountLineInterface {
        $orderShipping = (float)$order->getData('shipping_amount');
        if ($orderShipping <= self::EPSILON) {
            return $document instanceof Creditmemo ? null : $line;
        }
        $docShipping = (float)$document->getData('shipping_amount');
        if ($docShipping <= self::EPSILON) {
            return null;
        }
        $ratio = $this->share($docShipping, $orderShipping);

        return $line->withAmounts(round($line->getAmount() * $ratio, 2), round($line->getBaseAmount() * $ratio, 2));
    }
}
