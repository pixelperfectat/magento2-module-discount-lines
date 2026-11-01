<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\SalesRule\Model\ResourceModel\Rule as RuleResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Service\RuleLoader;

// Factories are generated at runtime and absent from a plain unit-test autoloader.
if (!class_exists(RuleFactory::class)) {
    require_once __DIR__ . '/../../Stub/RuleFactory.php';
}

class RuleLoaderTest extends TestCase
{
    private RuleFactory&MockObject $ruleFactory;
    private RuleResource&MockObject $ruleResource;
    private RuleLoader $loader;

    protected function setUp(): void
    {
        $this->ruleFactory = $this->createMock(RuleFactory::class);
        $this->ruleResource = $this->createMock(RuleResource::class);
        $this->loader = new RuleLoader($this->ruleFactory, $this->ruleResource);
    }

    private function rule(?int $loadedId): Rule&MockObject
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($loadedId);

        return $rule;
    }

    public function testExistingRuleIsLoadedByItsId(): void
    {
        $rule = $this->rule(5);
        $this->ruleFactory->method('create')->willReturn($rule);
        $this->ruleResource->expects($this->once())->method('load')->with($this->identicalTo($rule), 5);

        $this->assertSame($rule, $this->loader->load(5));
    }

    public function testUnknownRuleIsNull(): void
    {
        $this->ruleFactory->method('create')->willReturn($this->rule(null));

        $this->assertNull($this->loader->load(99));
    }

    public function testEachRuleIsLoadedOnceIncludingUnknownRules(): void
    {
        $known = $this->rule(5);
        $this->ruleFactory->expects($this->exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($known, $this->rule(null));
        $this->ruleResource->expects($this->exactly(2))->method('load');

        $this->assertSame($known, $this->loader->load(5));
        $this->assertNull($this->loader->load(99));
        $this->assertSame($known, $this->loader->load(5));
        $this->assertNull($this->loader->load(99));
    }
}
