<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\SalesRule\Model\Rule;

interface LabelResolverInterface
{
    /**
     * Resolve kind, code and customer-safe label of a rule (never description or rule name).
     *
     * @param Rule $rule
     * @param string|null $couponCode Coupon code applied to the quote, if any
     * @param int $storeId Order store view
     * @param string $fallbackLabel Label for an automatic rule without store label; empty = generic "Discount"
     * @return array{kind: string, code: string|null, label: string}
     */
    public function resolve(Rule $rule, ?string $couponCode, int $storeId, string $fallbackLabel = ''): array;
}
