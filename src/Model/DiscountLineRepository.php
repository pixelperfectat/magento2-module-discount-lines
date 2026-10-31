<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterfaceFactory;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord as DiscountLineRecordResource;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord\Collection;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord\CollectionFactory;

class DiscountLineRepository implements DiscountLineRepositoryInterface
{
    /**
     * @param DiscountLineRecordResource $resource
     * @param CollectionFactory $collectionFactory
     * @param DiscountLineInterfaceFactory $lineFactory
     */
    public function __construct(
        private readonly DiscountLineRecordResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DiscountLineInterfaceFactory $lineFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function saveForOrder(int $orderId, array $rows): void
    {
        $this->resource->replaceForOrder($orderId, $rows);
    }

    /**
     * @inheritDoc
     */
    public function getOrderLines(int $orderId): array
    {
        $collection = $this->orderCollection($orderId);
        $collection->addFieldToFilter('order_item_id', ['null' => true]);
        $collection->setOrder('sort_order', Collection::SORT_ORDER_ASC);
        $collection->setOrder('entity_id', Collection::SORT_ORDER_ASC);

        $lines = [];
        foreach ($collection as $record) {
            $lines[] = $this->lineFactory->create([
                'kind' => (string)$record->getData('kind'),
                'ruleId' => $record->getData('rule_id') !== null ? (int)$record->getData('rule_id') : null,
                'code' => $record->getData('code') !== null ? (string)$record->getData('code') : null,
                'label' => (string)$record->getData('label'),
                'amount' => (float)$record->getData('amount'),
                'baseAmount' => (float)$record->getData('base_amount'),
                'sortOrder' => (int)$record->getData('sort_order'),
            ]);
        }

        return $lines;
    }

    /**
     * @inheritDoc
     */
    public function getItemAmounts(int $orderId): array
    {
        $collection = $this->orderCollection($orderId);
        $collection->addFieldToFilter('order_item_id', ['notnull' => true]);

        $result = [];
        foreach ($collection as $record) {
            $itemId = (int)$record->getData('order_item_id');
            $key = $record->getData('rule_id') !== null
                ? (int)$record->getData('rule_id')
                : $record->getData('kind') . ':' . $record->getData('code');
            $current = $result[$itemId][$key] ?? ['amount' => 0.0, 'base_amount' => 0.0];
            $result[$itemId][$key] = [
                'amount' => $current['amount'] + (float)$record->getData('amount'),
                'base_amount' => $current['base_amount'] + (float)$record->getData('base_amount'),
            ];
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function hasLines(int $orderId): bool
    {
        return $this->orderCollection($orderId)->getSize() > 0;
    }

    /**
     * Collection filtered to one order.
     *
     * @param int $orderId
     * @return Collection
     */
    private function orderCollection(int $orderId): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', ['eq' => $orderId]);

        return $collection;
    }
}
