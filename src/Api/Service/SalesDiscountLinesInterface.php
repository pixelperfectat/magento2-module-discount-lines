<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api\Service;

use Magento\Sales\Model\AbstractModel;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

interface SalesDiscountLinesInterface
{
    /**
     * Get the discount lines of an order, invoice or credit memo.
     *
     * Orders return the stored lines; invoices and credit memos return the stored lines split by the
     * invoiced / refunded quantities, reconciled to the document discount. Empty when nothing is stored.
     *
     * @param AbstractModel $source Order, invoice or credit memo
     * @return DiscountLineInterface[]
     */
    public function getLines(AbstractModel $source): array;
}
