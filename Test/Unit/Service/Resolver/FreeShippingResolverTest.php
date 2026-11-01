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
        ?array $discounts = [],
        ?float $baseShippingDiscount = null
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
        $address->method('getBaseShippingDiscountAmount')->willReturn($baseShippingDiscount ?? $shippingDiscount);

        return $address;
    }

    private function quote(?string $couponCode = 'LAB-FREESHIP'): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCouponCode'])
            ->onlyMethods(['getStoreId'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn($couponCode);
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

    private function shippingEntry(float $amount, float $baseAmount, string $appliedTo): RuleDiscountInterface
    {
        $data = $this->createMock(DiscountData::class);
        $data->method('getAmount')->willReturn($amount);
        $data->method('getBaseAmount')->willReturn($baseAmount);
        $data->method('getAppliedTo')->willReturn($appliedTo);
        $entry = $this->createMock(RuleDiscountInterface::class);
        $entry->method('getDiscountData')->willReturn($data);

        return $entry;
    }

    private function anyLabel(): void
    {
        $this->labelResolver->method('resolve')
            ->willReturn(['kind' => 'automatic', 'code' => null, 'label' => 'Free shipping']);
    }

    public function testOnlyTheShippingPartsOfRuleDataAreDeductedRoundedToCents(): void
    {
        $this->ruleLoader->method('load')->willReturn($this->rule(9, 1));
        $this->anyLabel();
        $entries = [
            $this->shippingEntry(-1.0, -2.0, DiscountAppliedToInterface::APPLIED_TO_SHIPPING),
            $this->shippingEntry(-7.0, -7.0, DiscountAppliedToInterface::APPLIED_TO_ITEM),
            $this->shippingEntry(-0.5, -1.0, DiscountAppliedToInterface::APPLIED_TO_SHIPPING),
        ];

        $lines = $this->resolver->resolve($this->address(1, '9', -4.9449, $entries, -9.8449), $this->quote());

        $this->assertSame(3.44, $lines[0]->getAmount());
        $this->assertSame(6.84, $lines[0]->getBaseAmount());
    }

    public function testAddressWithoutRuleDataKeepsTheWholeShippingDiscount(): void
    {
        $this->ruleLoader->method('load')->willReturn($this->rule(9, 1));
        $this->anyLabel();

        $lines = $this->resolver->resolve($this->address(1, '9', -4.9, null, -9.8), $this->quote());

        $this->assertSame(4.9, $lines[0]->getAmount());
        $this->assertSame(9.8, $lines[0]->getBaseAmount());
    }

    public function testOnlyTheFirstFreeShippingRuleCarriesTheAmount(): void
    {
        $this->ruleLoader->method('load')->willReturnCallback(fn (int $id) => $this->rule($id, 1));
        $this->anyLabel();

        $lines = $this->resolver->resolve($this->address(1, '8,9', -4.9, [], -9.8), $this->quote());

        $this->assertSame(
            [[8, 4.9, 9.8, 0], [9, 0.0, 0.0, 1]],
            array_map(
                static fn($l): array => [$l->getRuleId(), $l->getAmount(), $l->getBaseAmount(), $l->getSortOrder()],
                $lines
            )
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function appliedRuleIdsProvider(): array
    {
        return ['trailing separator' => ['9,'], 'repeated rule' => ['9,9']];
    }

    /**
     * @dataProvider appliedRuleIdsProvider
     */
    public function testEachAppliedRuleIsLoadedOnce(string $applied): void
    {
        $this->ruleLoader->expects($this->once())->method('load')->with(9)->willReturn($this->rule(9, 1));
        $this->anyLabel();

        $this->assertCount(1, $this->resolver->resolve($this->address(1, $applied, -4.9), $this->quote()));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function couponProvider(): array
    {
        return ['with coupon' => ['SHIPFREE'], 'without coupon' => [null]];
    }

    /**
     * @dataProvider couponProvider
     */
    public function testQuoteCouponAndFallbackLabelArePassedToTheLabelResolver(?string $couponCode): void
    {
        $rule = $this->rule(9, 1);
        $this->ruleLoader->method('load')->willReturn($rule);
        $this->labelResolver->expects($this->once())->method('resolve')
            ->with($this->identicalTo($rule), $this->identicalTo($couponCode), 1, 'Free shipping')
            ->willReturn(['kind' => 'automatic', 'code' => null, 'label' => 'Free shipping']);

        $this->resolver->resolve($this->address(1, '9', -4.9), $this->quote($couponCode));
    }
}
