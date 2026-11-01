<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Service\DocumentLineLabel;

class DocumentLineLabelTest extends TestCase
{
    private DocumentLineLabel $labels;

    protected function setUp(): void
    {
        $this->labels = new DocumentLineLabel();
    }

    private function line(string $kind, ?string $code, string $label, float $amount = 0.0): DiscountLine
    {
        return new DiscountLine($kind, 1, $code, $label, $amount, $amount);
    }

    public function testCouponShowsItsCode(): void
    {
        $this->assertSame('Coupon SAVE10', $this->labels->format($this->line('coupon', 'SAVE10', 'SAVE10', 2.0)));
    }

    public function testFreeShippingByCouponShowsTheCouponCode(): void
    {
        $line = $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, 'SHIPFREE', 'SHIPFREE');

        $this->assertSame('Coupon SHIPFREE', $this->labels->format($line));
    }

    public function testAutomaticFreeShippingShowsItsLabel(): void
    {
        $line = $this->line(DiscountLineInterface::KIND_FREE_SHIPPING, null, 'Free shipping');

        $this->assertSame('Free shipping', $this->labels->format($line));
    }

    public function testZeroGiftAndFreeShippingLinesGetAnAmountText(): void
    {
        $this->assertSame('Gift', $this->labels->amountText($this->line('gift', 'FREEBIE', 'FREEBIE')));
        $this->assertSame('Free', $this->labels->amountText($this->line('free_shipping', null, 'Free shipping')));
    }

    public function testMoneyAndOtherZeroLinesHaveNoAmountText(): void
    {
        $this->assertNull($this->labels->amountText($this->line('gift', 'FREEBIE', 'FREEBIE', 8.0)));
        $this->assertNull($this->labels->amountText($this->line('free_shipping', null, 'Free shipping', 4.9)));
        $this->assertNull($this->labels->amountText($this->line('coupon', 'X', 'X')));
    }

    public function testValueIsNegativeForMoneyAndZeroOtherwise(): void
    {
        $this->assertSame(-2.5, $this->labels->value($this->line('coupon', 'X', 'X', 2.5)));
        $this->assertSame(0.0, $this->labels->value($this->line('gift', 'X', 'X')));
    }
}
