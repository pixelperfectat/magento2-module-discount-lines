<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;

class LabelResolver implements LabelResolverInterface
{
    /**
     * @inheritDoc
     */
    public function resolve(Rule $rule, ?string $couponCode, int $storeId, string $fallbackLabel = ''): array
    {
        $couponCode = $couponCode === null ? '' : trim($couponCode);
        $hasCoupon = (int)$rule->getCouponType() !== Rule::COUPON_TYPE_NO_COUPON;

        if ($hasCoupon && $couponCode !== '') {
            return ['kind' => DiscountLineInterface::KIND_COUPON, 'code' => $couponCode, 'label' => $couponCode];
        }

        $labels = (array)$rule->getStoreLabels();
        $label = (string)($labels[$storeId] ?? '');
        if ($label === '') {
            $label = (string)($labels[0] ?? '');
        }
        if ($label === '') {
            $label = $fallbackLabel !== '' ? $fallbackLabel : (string)__('Discount');
        }

        return ['kind' => DiscountLineInterface::KIND_AUTOMATIC, 'code' => null, 'label' => $label];
    }
}
