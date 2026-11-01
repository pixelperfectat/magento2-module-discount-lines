<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord;

class DiscountLineRecordTest extends TestCase
{
    public function testReplaceDeletesThenInsertsForTheSameOrderWithoutOwnTransaction(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->never())->method('beginTransaction');
        $order = [];
        $connection->expects($this->exactly(2))->method('delete')
            ->willReturnCallback(function (string $table, array $where) use (&$order): int {
                $order[] = ['delete', $where];

                return 0;
            });
        $connection->expects($this->exactly(2))->method('insertMultiple')
            ->willReturnCallback(function (string $table, array $rows) use (&$order): int {
                $order[] = ['insert', $rows[0]['order_id']];

                return 1;
            });
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);
        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($resourceConnection);
        $resource = new DiscountLineRecord($context);

        $resource->replaceForOrder(500, [['kind' => 'coupon']]);
        $resource->replaceForOrder(500, [['kind' => 'coupon']]);

        $this->assertSame(
            [['delete', ['order_id = ?' => 500]], ['insert', 500], ['delete', ['order_id = ?' => 500]], ['insert', 500]],
            $order
        );
    }
}
