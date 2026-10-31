<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\SalesRule\Model\Rule;

interface RuleLoaderInterface
{
    /**
     * Load a sales rule by id, null when it does not exist.
     *
     * @param int $ruleId
     * @return Rule|null
     */
    public function load(int $ruleId): ?Rule;
}
