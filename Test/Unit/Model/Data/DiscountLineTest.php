<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Model\Data;

use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

class DiscountLineTest extends TestCase
{
    public function testJsonSerializationIsTheContractArray(): void
    {
        $line = new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.3, 2);

        $this->assertInstanceOf(\JsonSerializable::class, $line);
        $this->assertSame($line->toArray(), $line->jsonSerialize());
        $this->assertSame(
            '{"kind":"coupon","rule_id":1,"code":"CODE","label":"CODE","amount":3.33,"base_amount":3.3}',
            json_encode($line)
        );
    }
}
