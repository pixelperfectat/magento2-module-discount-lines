<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

/**
 * One line per rule from the address extension attribute `discounts`.
 */
class RuleDiscountResolver implements DiscountLineResolverInterface
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
        $extension = $address->getExtensionAttributes();
        /** @var RuleDiscountInterface[]|null $discounts */
        // The extension interface is generated at runtime and unknown to static analysis.
        // @phpstan-ignore-next-line
        $discounts = $extension !== null ? $extension->getDiscounts() : null;
        if (!$discounts) {
            return [];
        }

        // Core adds a separate entry for the shipping part of an apply-to-shipping rule: merge per rule.
        $perRule = [];
        foreach ($discounts as $discount) {
            $data = $discount->getDiscountData();
            $ruleId = (int)$discount->getRuleID();
            $perRule[$ruleId] ??= [0.0, 0.0];
            $perRule[$ruleId][0] += abs((float)$data->getAmount());
            $perRule[$ruleId][1] += abs((float)$data->getBaseAmount());
        }

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
}
