<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Quote\Model\Quote\Item\AbstractItem;

/**
 * Extension point: decides which rule owns the discount of an item that has no per-rule breakdown and several
 * applied rules (e.g. a gift added mid-collect by a promotion module). Register strategies in `di.xml` as an array
 * argument of the rule discount resolver.
 */
interface ItemRuleAttributorInterface
{
    /**
     * Rule id that carries the item discount, null when this strategy cannot tell.
     *
     * @param AbstractItem $item
     * @param int[] $ruleIds Rules applied to the item
     * @return int|null
     */
    public function attribute(AbstractItem $item, array $ruleIds): ?int;
}
