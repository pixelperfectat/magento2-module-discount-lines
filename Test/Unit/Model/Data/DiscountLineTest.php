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
        $this->assertSame(
            '{"kind":"coupon","rule_id":1,"code":"CODE","label":"CODE","amount":3.33,"base_amount":3.3,'
            . '"sort_order":2}',
            json_encode($line)
        );
    }

    public function testJsonOmitsNullCodeLikeRest(): void
    {
        $line = new DiscountLine('automatic', 2, null, 'Sale', 1.0, 1.0, 0);

        $this->assertSame(
            '{"kind":"automatic","rule_id":2,"label":"Sale","amount":1,"base_amount":1,"sort_order":0}',
            json_encode($line)
        );
    }
}
