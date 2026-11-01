<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PixelPerfect\DiscountLines\Model\DiscountLineRecord;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord as DiscountLineRecordResource;

class Collection extends AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(DiscountLineRecord::class, DiscountLineRecordResource::class);
    }
}
