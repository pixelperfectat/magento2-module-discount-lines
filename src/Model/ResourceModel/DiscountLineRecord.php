<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model\ResourceModel;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class DiscountLineRecord extends AbstractDb
{
    public const TABLE = 'pp_discount_line';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(self::TABLE, 'entity_id');
    }

    /**
     * Atomically replace all stored rows of an order.
     *
     * @param int $orderId
     * @param array<int, array<string, mixed>> $rows Column => value maps, order_id is added here
     * @return void
     * @throws LocalizedException
     */
    public function replaceForOrder(int $orderId, array $rows): void
    {
        $connection = $this->getConnection();
        $table = $this->getMainTable();
        $connection->beginTransaction();
        try {
            $connection->delete($table, ['order_id = ?' => $orderId]);
            if ($rows !== []) {
                $connection->insertMultiple(
                    $table,
                    array_map(static fn (array $row): array => ['order_id' => $orderId] + $row, $rows)
                );
            }
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();
            throw new LocalizedException(__('Could not save the discount lines.'), $e);
        }
    }
}
