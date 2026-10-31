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

    /**
     * @param DiscountLineRepositoryInterface $repository
     * @param ProportionalSplitterInterface $splitter
     */
    public function __construct(
        private readonly DiscountLineRepositoryInterface $repository,
        private readonly ProportionalSplitterInterface $splitter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getLines(AbstractModel $source): array
    {
        if ($source instanceof Order) {
            return $this->repository->getOrderLines((int)$source->getId());
        }

        $orderId = (int)$source->getData('order_id');
        $lines = $this->repository->getOrderLines($orderId);
        if ($lines === []) {
            return [];
        }

        return $this->splitForDocument($source, $orderId, $lines);
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
        $orderDiscount = abs((float)$order->getData('discount_amount'));
        $share = $orderDiscount > self::EPSILON ? min(1.0, $docDiscount / $orderDiscount) : 0.0;
        $baseOrderDiscount = abs((float)$order->getData('base_discount_amount'));
        $baseShare = $baseOrderDiscount > self::EPSILON ? min(1.0, $docBaseDiscount / $baseOrderDiscount) : 0.0;
        $perRule = $this->documentRuleAmounts($document, $itemAmounts);
        $carriesShipping = $this->carriesFirstShipping($document, $order);

        $result = [];
        foreach ($lines as $line) {
            $ruleId = $line->getRuleId();
            if ($line->getKind() === DiscountLineInterface::KIND_FREE_SHIPPING) {
                if ($carriesShipping) {
                    $result[] = $line;
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

        $result = array_values(array_filter($result, static fn(DiscountLineInterface $line): bool => (
            $line->getAmount() > 0.0
            || $line->getBaseAmount() > 0.0
            || in_array(
                $line->getKind(),
                [DiscountLineInterface::KIND_GIFT, DiscountLineInterface::KIND_FREE_SHIPPING],
                true
            )
        )));

        return $this->splitter->split($result, $docDiscount, $docBaseDiscount);
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
            $orderItemId = (int)$item->getOrderItemId();
            $parentId = (int)$orderItem->getParentItemId();
            if (!isset($itemAmounts[$orderItemId]) || ($parentId > 0 && isset($itemAmounts[$parentId]))) {
                continue;
            }
            $ordered = (float)$orderItem->getQtyOrdered();
            if ($ordered <= 0.0) {
                continue;
            }
            $ratio = (float)$item->getQty() / $ordered;
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
     * Whether the document is the first invoice / credit memo that carries shipping.
     *
     * @param AbstractModel $document
     * @param AbstractModel $order
     * @return bool
     */
    private function carriesFirstShipping(AbstractModel $document, AbstractModel $order): bool
    {
        $shipping = (float)$document->getData('shipping_amount');
        if ($shipping <= self::EPSILON) {
            return false;
        }
        $field = $document instanceof Creditmemo ? 'shipping_refunded' : 'shipping_invoiced';
        $already = (float)$order->getData($field);
        if ($document->getId()) {
            $already -= $shipping;
        }

        return $already <= self::EPSILON;
    }
}
