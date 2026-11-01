<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;

class RuleLoader implements RuleLoaderInterface
{
    /** @var array<int, Rule|null> */
    private array $cache = [];

    /**
     * @param RuleFactory $ruleFactory
     * @param RuleResource $ruleResource
     */
    public function __construct(
        private readonly RuleFactory $ruleFactory,
        private readonly RuleResource $ruleResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function load(int $ruleId): ?Rule
    {
        if (!array_key_exists($ruleId, $this->cache)) {
            $rule = $this->ruleFactory->create();
            $this->ruleResource->load($rule, $ruleId);
            $this->cache[$ruleId] = $rule->getId() ? $rule : null;
        }

        return $this->cache[$ruleId];
    }
}
