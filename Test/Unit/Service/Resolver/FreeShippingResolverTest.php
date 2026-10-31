<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Service\Resolver\FreeShippingResolver;

class FreeShippingResolverTest extends TestCase
{
    private RuleLoaderInterface&MockObject $ruleLoader;
    private LabelResolverInterface&MockObject $labelResolver;
    private FreeShippingResolver $resolver;

    protected function setUp(): void
    {
        $this->ruleLoader = $this->createMock(RuleLoaderInterface::class);
        $this->labelResolver = $this->createMock(LabelResolverInterface::class);
        $this->resolver = new FreeShippingResolver($this->ruleLoader, $this->labelResolver);
    }

    private function address(int $freeShipping, string $ruleIds, float $shippingDiscount): Address
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'getFreeShipping',
                'getAppliedRuleIds',
                'getShippingDiscountAmount',
                'getBaseShippingDiscountAmount',
            ])
            ->getMock();
        $address->method('getFreeShipping')->willReturn($freeShipping);
        $address->method('getAppliedRuleIds')->willReturn($ruleIds);
        $address->method('getShippingDiscountAmount')->willReturn($shippingDiscount);
        $address->method('getBaseShippingDiscountAmount')->willReturn($shippingDiscount);

        return $address;
    }

    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->onlyMethods(['getStoreId'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn('LAB-FREESHIP');
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }

    private function rule(int $id, int $freeShipping): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $rule->method('getId')->willReturn($id);
        $rule->setData('simple_free_shipping', $freeShipping);

        return $rule;
    }

    public function testFreeShippingRuleWithZeroItemDiscountYieldsLine(): void
    {
        $this->ruleLoader->method('load')->willReturnMap([
            [8, $this->rule(8, 0)],
            [9, $this->rule(9, 1)],
        ]);
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'coupon', 'code' => 'LAB-FREESHIP', 'label' => 'LAB-FREESHIP']);

        $lines = $this->resolver->resolve($this->address(1, '8,9', 0.0), $this->quote());

        $this->assertCount(1, $lines);
        $this->assertSame(DiscountLineInterface::KIND_FREE_SHIPPING, $lines[0]->getKind());
        $this->assertSame(9, $lines[0]->getRuleId());
        $this->assertSame('LAB-FREESHIP', $lines[0]->getCode());
        $this->assertSame('LAB-FREESHIP', $lines[0]->getLabel());
        $this->assertSame(0.0, $lines[0]->getAmount());
    }

    public function testAmountIsShippingDiscount(): void
    {
        $this->ruleLoader->method('load')->willReturn($this->rule(9, 1));
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'automatic', 'code' => null, 'label' => 'Free shipping']);

        $lines = $this->resolver->resolve($this->address(1, '9', -4.9), $this->quote());

        $this->assertSame(4.9, $lines[0]->getAmount());
    }

    public function testNoFreeShippingFlagYieldsNothing(): void
    {
        $this->ruleLoader->expects($this->never())->method('load');

        $this->assertSame([], $this->resolver->resolve($this->address(0, '9', 0.0), $this->quote()));
    }
}
