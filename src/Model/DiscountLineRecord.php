<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model;

use Magento\Framework\Model\AbstractModel;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord as DiscountLineRecordResource;

/**
 * One stored discount line row (order-level when order_item_id is null, item-level otherwise).
 */
class DiscountLineRecord extends AbstractModel
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(DiscountLineRecordResource::class);
    }
}
