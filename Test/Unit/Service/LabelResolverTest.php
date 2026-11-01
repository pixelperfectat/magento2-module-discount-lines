<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Service\LabelResolver;

class LabelResolverTest extends TestCase
{
    private LabelResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LabelResolver();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $labels
     */
    private function rule(array $data, array $labels = []): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreLabels'])
            ->getMock();
        $rule->method('getStoreLabels')->willReturn($labels);
        $rule->setData($data);

        return $rule;
    }

    public function testCouponRuleUsesCode(): void
    {
        $rule = $this->rule(['coupon_type' => Rule::COUPON_TYPE_SPECIFIC, 'name' => 'LAB percent']);

        $result = $this->resolver->resolve($rule, 'LAB-PERCENT-15', 1);

        $this->assertSame(
            ['kind' => DiscountLineInterface::KIND_COUPON, 'code' => 'LAB-PERCENT-15', 'label' => 'LAB-PERCENT-15'],
            $result
        );
    }

    public function testAutomaticRuleUsesStoreLabel(): void
    {
        $rule = $this->rule(['coupon_type' => Rule::COUPON_TYPE_NO_COUPON], [0 => 'Default', 1 => 'Autumn sale −5 %']);

        $result = $this->resolver->resolve($rule, 'LAB-PERCENT-15', 1);

        $this->assertSame(DiscountLineInterface::KIND_AUTOMATIC, $result['kind']);
        $this->assertNull($result['code']);
        $this->assertSame('Autumn sale −5 %', $result['label']);
    }

    public function testAutomaticRuleFallsBackToDefaultStoreLabel(): void
    {
        $rule = $this->rule(['coupon_type' => Rule::COUPON_TYPE_NO_COUPON], [0 => 'Default label']);

        $this->assertSame('Default label', $this->resolver->resolve($rule, null, 2)['label']);
    }

    public function testAutomaticRuleWithoutLabelUsesGenericDiscount(): void
    {
        $rule = $this->rule(['coupon_type' => Rule::COUPON_TYPE_NO_COUPON]);

        $this->assertSame('Discount', $this->resolver->resolve($rule, null, 1)['label']);
    }

    public function testCustomFallbackLabelIsUsed(): void
    {
        $rule = $this->rule(['coupon_type' => Rule::COUPON_TYPE_NO_COUPON]);

        $this->assertSame('Free shipping', $this->resolver->resolve($rule, null, 1, 'Free shipping')['label']);
    }

    public function testDescriptionAndNameNeverUsed(): void
    {
        $rule = $this->rule([
            'coupon_type' => Rule::COUPON_TYPE_NO_COUPON,
            'description' => 'INTERNAL description',
            'name' => 'LAB auto',
        ]);

        $result = $this->resolver->resolve($rule, null, 1);

        $this->assertSame('Discount', $result['label']);
    }
}
