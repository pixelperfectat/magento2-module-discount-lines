<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service\Resolver;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Api\Data\DiscountDataInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Exception\UnattributableDiscountException;
use PixelPerfect\DiscountLines\Service\Resolver\RuleDiscountResolver;

class RuleDiscountResolverTest extends TestCase
{
    private RuleLoaderInterface&MockObject $ruleLoader;
    private LabelResolverInterface&MockObject $labelResolver;
    private RuleDiscountResolver $resolver;

    protected function setUp(): void
    {
        $this->ruleLoader = $this->createMock(RuleLoaderInterface::class);
        $this->labelResolver = $this->createMock(LabelResolverInterface::class);
        $this->resolver = new RuleDiscountResolver($this->ruleLoader, $this->labelResolver);
    }

    private function discount(int $ruleId, float $amount, float $baseAmount): RuleDiscountInterface
    {
        $data = $this->createMock(DiscountDataInterface::class);
        $data->method('getAmount')->willReturn($amount);
        $data->method('getBaseAmount')->willReturn($baseAmount);
        $discount = $this->createMock(RuleDiscountInterface::class);
        $discount->method('getRuleID')->willReturn($ruleId);
        $discount->method('getDiscountData')->willReturn($data);
        $discount->method('getRuleLabel')->willReturn('INTERNAL description');

        return $discount;
    }

    /**
     * @param RuleDiscountInterface[] $discounts
     */
    private function address(array $discounts): Address
    {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $extension->method('getDiscounts')->willReturn($discounts);
        $address = $this->createMock(Address::class);
        $address->method('getExtensionAttributes')->willReturn($extension);
        $address->method('getAllItems')->willReturn([]);

        return $address;
    }

    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->onlyMethods(['getStoreId'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn('LAB-PERCENT-15');
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }

    private function rule(int $id): Rule
    {
        $rule = $this->createMock(Rule::class);
        $rule->method('getId')->willReturn($id);

        return $rule;
    }

    public function testBuildsOneLinePerRuleInOrder(): void
    {
        $this->ruleLoader->method('load')->willReturnCallback(fn (int $id) => $this->rule($id));
        $this->labelResolver->method('resolve')->willReturnCallback(
            fn (Rule $rule) => $rule->getId() === 1
                ? ['kind' => 'coupon', 'code' => 'LAB-PERCENT-15', 'label' => 'LAB-PERCENT-15']
                : ['kind' => 'automatic', 'code' => null, 'label' => 'Autumn sale −5 %']
        );

        $lines = $this->resolver->resolve(
            $this->address([$this->discount(1, -2.25, -2.0), $this->discount(2, -1.95, -1.7)]),
            $this->quote()
        );

        $this->assertCount(2, $lines);
        $this->assertSame(
            ['kind' => 'coupon', 'rule_id' => 1, 'code' => 'LAB-PERCENT-15', 'label' => 'LAB-PERCENT-15',
                'amount' => 2.25, 'base_amount' => 2.0],
            $lines[0]->toArray()
        );
        $this->assertSame('Autumn sale −5 %', $lines[1]->getLabel());
        $this->assertSame(1.95, $lines[1]->getAmount());
        $this->assertSame(1.7, $lines[1]->getBaseAmount());
        $this->assertSame([0, 1], [$lines[0]->getSortOrder(), $lines[1]->getSortOrder()]);
    }

    public function testRuleLabelFromDiscountDataIsNeverUsed(): void
    {
        $this->ruleLoader->method('load')->willReturn($this->rule(1));
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'automatic', 'code' => null, 'label' => 'Discount']);

        $lines = $this->resolver->resolve($this->address([$this->discount(1, 1.0, 1.0)]), $this->quote());

        $this->assertSame('Discount', $lines[0]->getLabel());
    }

    public function testRuleWithZeroAmountIsSkipped(): void
    {
        $this->ruleLoader->expects($this->never())->method('load');

        $lines = $this->resolver->resolve($this->address([$this->discount(1, 0.0, 0.0)]), $this->quote());

        $this->assertSame([], $lines);
    }

    public function testMissingRuleIsSkipped(): void
    {
        $this->ruleLoader->method('load')->willReturn(null);

        $lines = $this->resolver->resolve($this->address([$this->discount(9, 1.0, 1.0)]), $this->quote());

        $this->assertSame([], $lines);
    }

    public function testNoDiscountsReturnsEmpty(): void
    {
        $this->assertSame([], $this->resolver->resolve($this->address([]), $this->quote()));
    }

    /**
     * @param Quote\Item[] $items
     * @param RuleDiscountInterface[] $discounts
     */
    private function addressWithItems(array $discounts, array $items): Address
    {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $extension->method('getDiscounts')->willReturn($discounts);
        $address = $this->createMock(Address::class);
        $address->method('getExtensionAttributes')->willReturn($extension);
        $address->method('getAllItems')->willReturn($items);

        return $address;
    }

    private function item(float $discount, string $ruleIds, bool $hasBreakdown): Quote\Item
    {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $breakdown = $hasBreakdown ? [$this->discount(1, $discount, $discount)] : null;
        $extension->method('getDiscounts')->willReturn($breakdown);
        $item = $this->getMockBuilder(Quote\Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'getExtensionAttributes'])
            ->addMethods(['getDiscountAmount', 'getBaseDiscountAmount', 'getAppliedRuleIds'])
            ->getMock();
        $item->method('getParentItem')->willReturn(null);
        $item->method('getExtensionAttributes')->willReturn($extension);
        $item->method('getDiscountAmount')->willReturn($discount);
        $item->method('getBaseDiscountAmount')->willReturn($discount);
        $item->method('getAppliedRuleIds')->willReturn($ruleIds);

        return $item;
    }

    public function testItemWithoutIdAndSingleAppliedRuleIsCreditedToThatRule(): void
    {
        $this->ruleLoader->method('load')->willReturnCallback(fn (int $id) => $this->rule($id));
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'automatic', 'code' => null, 'label' => 'Autumn sale']);
        // rule 2 is in the address breakdown for the first item (3.00), the second item (1.50) has none
        $address = $this->addressWithItems(
            [$this->discount(2, -3.0, -3.0)],
            [$this->item(3.0, '2', true), $this->item(1.5, '2', false)]
        );

        $lines = $this->resolver->resolve($address, $this->quote());

        $this->assertCount(1, $lines);
        $this->assertSame(4.5, $lines[0]->getAmount());
        $this->assertSame(2, $lines[0]->getRuleId());
    }

    public function testItemWithoutIdAndSeveralRulesStoresNull(): void
    {
        $address = $this->addressWithItems([], [$this->item(1.5, '1,2', false)]);

        $this->expectException(UnattributableDiscountException::class);

        $this->resolver->resolve($address, $this->quote());
    }
}
