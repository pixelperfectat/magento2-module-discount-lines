<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

/**
 * Free-shipping rules produce a `free_shipping` line even when they discount no item.
 */
class FreeShippingResolver implements DiscountLineResolverInterface
{
    /**
     * @param RuleLoaderInterface $ruleLoader
     * @param LabelResolverInterface $labelResolver
     */
    public function __construct(
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly LabelResolverInterface $labelResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Address $address, Quote $quote): array
    {
        if (!$address->getFreeShipping()) {
            return [];
        }

        $amount = abs((float)$address->getShippingDiscountAmount());
        $baseAmount = abs((float)$address->getBaseShippingDiscountAmount());
        $couponCode = $quote->getCouponCode() !== null ? (string)$quote->getCouponCode() : null;
        $ruleIds = array_filter(array_map('intval', explode(',', (string)$address->getAppliedRuleIds())));

        $lines = [];
        foreach (array_unique($ruleIds) as $ruleId) {
            $rule = $this->ruleLoader->load($ruleId);
            if ($rule === null || (int)$rule->getSimpleFreeShipping() <= 0) {
                continue;
            }
            $resolved = $this->labelResolver->resolve(
                $rule,
                $couponCode,
                (int)$quote->getStoreId(),
                (string)__('Free shipping')
            );
            $first = $lines === [];
            $lines[] = new DiscountLine(
                DiscountLineInterface::KIND_FREE_SHIPPING,
                $ruleId,
                $resolved['code'],
                $resolved['label'],
                $first ? $amount : 0.0,
                $first ? $baseAmount : 0.0,
                count($lines)
            );
        }

        return $lines;
    }
}
