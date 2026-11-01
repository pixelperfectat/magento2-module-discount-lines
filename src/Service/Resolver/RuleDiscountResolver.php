<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\ItemRuleAttributorInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Exception\UnattributableDiscountException;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

/**
 * One line per rule from the address extension attribute `discounts`.
 */
class RuleDiscountResolver implements DiscountLineResolverInterface
{
    private const EPSILON = 0.00001;

    /**
     * @param RuleLoaderInterface $ruleLoader
     * @param LabelResolverInterface $labelResolver
     * @param ItemRuleAttributorInterface[] $attributors Strategies for items with several rules and no breakdown
     */
    public function __construct(
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly LabelResolverInterface $labelResolver,
        private readonly array $attributors = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Address $address, Quote $quote): array
    {
        $extension = $address->getExtensionAttributes();
        /** @var RuleDiscountInterface[]|null $discounts */
        // The extension interface is generated at runtime and unknown to static analysis.
        // @phpstan-ignore-next-line
        $discounts = $extension !== null ? $extension->getDiscounts() : null;

        // Core adds a separate entry for the shipping part of an apply-to-shipping rule: merge per rule.
        $perRule = [];
        foreach ($discounts ?: [] as $discount) {
            $data = $discount->getDiscountData();
            $ruleId = (int)$discount->getRuleID();
            $perRule[$ruleId] ??= [0.0, 0.0];
            $perRule[$ruleId][0] += abs((float)$data->getAmount());
            $perRule[$ruleId][1] += abs((float)$data->getBaseAmount());
        }
        $this->addItemsWithoutBreakdown($address, $perRule);

        $lines = [];
        foreach ($perRule as $ruleId => [$amount, $baseAmount]) {
            if ($amount <= 0.0 && $baseAmount <= 0.0) {
                continue;
            }
            $rule = $this->ruleLoader->load($ruleId);
            if ($rule === null) {
                continue;
            }
            $resolved = $this->labelResolver->resolve(
                $rule,
                $quote->getCouponCode() !== null ? (string)$quote->getCouponCode() : null,
                (int)$quote->getStoreId()
            );
            $lines[] = new DiscountLine(
                $resolved['kind'],
                $ruleId,
                $resolved['code'],
                $resolved['label'],
                round($amount, 2),
                round($baseAmount, 2),
                count($lines)
            );
        }

        return $lines;
    }

    /**
     * Credit items that carry a discount but no per-rule breakdown.
     *
     * Core only records the breakdown for items that already have an id; items collected before they are saved
     * (add to cart, a gift added mid-collect) are missing from the address breakdown. One applied rule is credited
     * with the item's discount; several rules cannot be told apart.
     *
     * @param Address $address
     * @param array<int, array{0: float, 1: float}> $perRule rule_id => [amount, base amount], extended in place
     * @return void
     * @throws UnattributableDiscountException
     */
    private function addItemsWithoutBreakdown(Address $address, array &$perRule): void
    {
        foreach ($address->getAllItems() as $item) {
            if ($this->isChild($item) || abs((float)$item->getDiscountAmount()) < self::EPSILON) {
                continue;
            }
            $itemExtension = $item->getExtensionAttributes();
            // The extension interface is generated at runtime and unknown to static analysis.
            // @phpstan-ignore-next-line
            if ($itemExtension !== null && $itemExtension->getDiscounts()) {
                continue;
            }
            $applied = explode(',', (string)$item->getAppliedRuleIds());
            $ruleIds = array_unique(array_filter(array_map('intval', $applied)));
            $ruleId = count($ruleIds) === 1 ? (int)reset($ruleIds) : $this->attribute($item, $ruleIds);
            if ($ruleId === null) {
                throw new UnattributableDiscountException(
                    __('The discount of an item cannot be attributed to a single rule.')
                );
            }
            $perRule[$ruleId] ??= [0.0, 0.0];
            $perRule[$ruleId][0] += abs((float)$item->getDiscountAmount());
            $perRule[$ruleId][1] += abs((float)$item->getBaseDiscountAmount());
        }
    }

    /**
     * Ask the registered strategies which rule owns the discount of an item with several rules.
     *
     * @param AbstractItem $item
     * @param int[] $ruleIds
     * @return int|null
     */
    private function attribute(AbstractItem $item, array $ruleIds): ?int
    {
        foreach ($this->attributors as $attributor) {
            $ruleId = $attributor->attribute($item, array_values($ruleIds));
            if ($ruleId !== null && in_array($ruleId, $ruleIds, true)) {
                return $ruleId;
            }
        }

        return null;
    }

    /**
     * Whether the item is a child of a composite item (the parent carries the discount).
     *
     * @param AbstractItem $item
     * @return bool
     */
    private function isChild(AbstractItem $item): bool
    {
        // getParentItem() is documented as non-null but returns null for top-level items.
        // @phpstan-ignore-next-line
        return $item->getParentItem() !== null;
    }
}
