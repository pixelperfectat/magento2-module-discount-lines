<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteLineStoreInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use Throwable;

class QuoteLineStore implements QuoteLineStoreInterface
{
    /**
     * @param SerializerInterface $serializer
     */
    public function __construct(private readonly SerializerInterface $serializer)
    {
    }

    /**
     * @inheritDoc
     */
    public function store(Address $address, array $lines): void
    {
        if ($lines === []) {
            $address->setData(self::DATA_KEY, null);

            return;
        }
        $rows = [];
        foreach ($lines as $line) {
            $rows[] = $line->toArray() + ['sort_order' => $line->getSortOrder()];
        }
        $address->setData(self::DATA_KEY, $this->serializer->serialize($rows));
    }

    /**
     * @inheritDoc
     */
    public function load(Address $address): array
    {
        $raw = $address->getData(self::DATA_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $rows = $this->serializer->unserialize($raw);
        } catch (Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $line = is_array($row) ? $this->build($row) : null;
            if ($line === null) {
                return [];
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Build a line from a stored row, null when the row is invalid.
     *
     * @param array<string, mixed> $row
     * @return DiscountLineInterface|null
     */
    private function build(array $row): ?DiscountLineInterface
    {
        if (!isset($row['kind'], $row['label']) || !is_string($row['kind']) || !is_string($row['label'])) {
            return null;
        }

        return new DiscountLine(
            $row['kind'],
            isset($row['rule_id']) ? (int)$row['rule_id'] : null,
            isset($row['code']) ? (string)$row['code'] : null,
            $row['label'],
            (float)($row['amount'] ?? 0.0),
            (float)($row['base_amount'] ?? 0.0),
            (int)($row['sort_order'] ?? 0)
        );
    }
}
