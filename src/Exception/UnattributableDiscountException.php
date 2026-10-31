<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The discount of an address cannot be split by rule (several rules on an item without a per-rule breakdown).
 */
class UnattributableDiscountException extends LocalizedException
{
}
