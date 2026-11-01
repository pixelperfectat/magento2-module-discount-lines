<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DocumentLineLabelInterface;

class DocumentLineLabel implements DocumentLineLabelInterface
{
    /**
     * @inheritDoc
     */
    public function format(DiscountLineInterface $line): string
    {
        $code = (string)$line->getCode();

        return match (true) {
            $line->getKind() === DiscountLineInterface::KIND_COUPON => (string)__('Coupon %1', $code),
            $line->getKind() === DiscountLineInterface::KIND_GIFT => (string)__('Gift: %1', $code),
            $line->getKind() === DiscountLineInterface::KIND_FREE_SHIPPING && $code !== ''
                => (string)__('Coupon %1', $code),
            default => $line->getLabel(),
        };
    }

    /**
     * @inheritDoc
     */
    public function amountText(DiscountLineInterface $line): ?string
    {
        if (abs($line->getAmount()) > 0.00001) {
            return null;
        }

        return match ($line->getKind()) {
            DiscountLineInterface::KIND_GIFT => (string)__('Gift'),
            DiscountLineInterface::KIND_FREE_SHIPPING => (string)__('Free'),
            default => null,
        };
    }

    /**
     * @inheritDoc
     */
    public function value(DiscountLineInterface $line, bool $base = false): float
    {
        $amount = $base ? $line->getBaseAmount() : $line->getAmount();

        return $amount > 0.0 ? -$amount : 0.0;
    }
}
