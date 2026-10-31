<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service\Resolver;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Api\Data\DiscountAppliedToInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use Magento\SalesRule\Model\Data\DiscountData;
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

    /**
     * @param RuleDiscountInterface[] $discounts
     */
    private function address(
        int $freeShipping,
        string $ruleIds,
        float $shippingDiscount,
        array $discounts = []
    ): Address {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $extension->method('getDiscounts')->willReturn($discounts);
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods([
                'getFreeShipping',
                'getAppliedRuleIds',
                'getShippingDiscountAmount',
                'getBaseShippingDiscountAmount',
            ])
            ->onlyMethods(['getExtensionAttributes'])
            ->getMock();
        $address->method('getExtensionAttributes')->willReturn($extension);
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

    public function testShippingPartAlreadyInRuleDataIsNotCountedAgain(): void
    {
        $this->ruleLoader->method('load')->willReturn($this->rule(9, 1));
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'coupon', 'code' => 'X', 'label' => 'X']);
        $data = $this->createMock(DiscountData::class);
        $data->method('getAmount')->willReturn(-4.9);
        $data->method('getBaseAmount')->willReturn(-4.9);
        $data->method('getAppliedTo')->willReturn(DiscountAppliedToInterface::APPLIED_TO_SHIPPING);
        $entry = $this->createMock(RuleDiscountInterface::class);
        $entry->method('getDiscountData')->willReturn($data);

        $lines = $this->resolver->resolve($this->address(1, '9', -4.9, [$entry]), $this->quote());

        $this->assertCount(1, $lines);
        $this->assertSame(0.0, $lines[0]->getAmount());
        $this->assertSame(0.0, $lines[0]->getBaseAmount());
    }
}
