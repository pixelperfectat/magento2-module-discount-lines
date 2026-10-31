<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Model;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterfaceFactory;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\Model\DiscountLineRecord;
use PixelPerfect\DiscountLines\Model\DiscountLineRepository;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord as RecordResource;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord\Collection;
use PixelPerfect\DiscountLines\Model\ResourceModel\DiscountLineRecord\CollectionFactory;

// Factories are generated at runtime and absent from a plain unit-test autoloader.
if (!class_exists(CollectionFactory::class)) {
    require_once __DIR__ . '/../../Stub/DiscountLineRecordCollectionFactory.php';
}
if (!class_exists(DiscountLineInterfaceFactory::class)) {
    require_once __DIR__ . '/../../Stub/DiscountLineInterfaceFactory.php';
}

class DiscountLineRepositoryTest extends TestCase
{
    private RecordResource&MockObject $resource;
    private CollectionFactory&MockObject $collectionFactory;
    private DiscountLineRepository $repository;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(RecordResource::class);
        $this->collectionFactory = $this->createMock(CollectionFactory::class);
        $lineFactory = $this->createMock(DiscountLineInterfaceFactory::class);
        $lineFactory->method('create')->willReturnCallback(
            static fn (array $a): DiscountLine => new DiscountLine(
                $a['kind'],
                $a['ruleId'],
                $a['code'],
                $a['label'],
                $a['amount'],
                $a['baseAmount'],
                $a['sortOrder']
            )
        );
        $this->repository = new DiscountLineRepository($this->resource, $this->collectionFactory, $lineFactory);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function collection(array $rows, int $size = 0): Collection&MockObject
    {
        $records = [];
        foreach ($rows as $row) {
            $record = $this->createMock(DiscountLineRecord::class);
            $record->method('getData')->willReturnCallback(static fn ($key = '') => $row[$key] ?? null);
            $records[] = $record;
        }
        $collection = $this->createMock(Collection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($records));
        $collection->method('getSize')->willReturn($size);
        $this->collectionFactory->method('create')->willReturn($collection);

        return $collection;
    }

    public function testGetOrderLinesFiltersOrderLevelRowsOrderedBySortOrder(): void
    {
        $collection = $this->collection([
            ['rule_id' => '2', 'kind' => 'automatic', 'code' => null, 'label' => 'Spring',
                'amount' => '3.1500', 'base_amount' => '2.9000', 'sort_order' => '0'],
            ['rule_id' => null, 'kind' => 'automatic', 'code' => null, 'label' => 'Discount',
                'amount' => '0.0100', 'base_amount' => '0.0100', 'sort_order' => '1'],
        ]);
        $filters = [];
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, $cond) use (&$filters, $collection) {
                $filters[$field] = $cond;

                return $collection;
            }
        );
        $collection->expects($this->atLeastOnce())->method('setOrder')
            ->with($this->logicalOr('sort_order', 'entity_id'), 'ASC');

        $lines = $this->repository->getOrderLines(500);

        $this->assertSame(['eq' => 500], $filters['order_id']);
        $this->assertSame(['null' => true], $filters['order_item_id']);
        $this->assertCount(2, $lines);
        $this->assertSame(2, $lines[0]->getRuleId());
        $this->assertSame(3.15, $lines[0]->getAmount());
        $this->assertSame(2.9, $lines[0]->getBaseAmount());
        $this->assertSame(0, $lines[0]->getSortOrder());
        $this->assertNull($lines[1]->getRuleId());
        $this->assertNull($lines[1]->getCode());
        $this->assertSame(1, $lines[1]->getSortOrder());
    }

    public function testGetItemAmountsShapeAndRounding(): void
    {
        $collection = $this->collection([
            ['order_item_id' => '901', 'rule_id' => '1', 'amount' => '0.1000', 'base_amount' => '0.1000'],
            ['order_item_id' => '901', 'rule_id' => '1', 'amount' => '0.2000', 'base_amount' => '0.2000'],
            ['order_item_id' => '901', 'rule_id' => '2', 'amount' => '1.0000', 'base_amount' => '0.9000'],
            ['order_item_id' => '902', 'rule_id' => '1', 'amount' => '2.5000', 'base_amount' => '2.5000'],
            ['order_item_id' => '902', 'rule_id' => null, 'amount' => '9.0000', 'base_amount' => '9.0000'],
        ]);
        $collection->method('addFieldToFilter')->willReturnSelf();

        $this->assertSame(
            [
                901 => [
                    1 => ['amount' => 0.3, 'base_amount' => 0.3],
                    2 => ['amount' => 1.0, 'base_amount' => 0.9],
                ],
                902 => [1 => ['amount' => 2.5, 'base_amount' => 2.5]],
            ],
            $this->repository->getItemAmounts(500)
        );
    }

    public function testHasLines(): void
    {
        $this->collection([], 3)->method('addFieldToFilter')->willReturnSelf();
        $this->assertTrue($this->repository->hasLines(500));
    }

    public function testHasNoLines(): void
    {
        $this->collection([], 0)->method('addFieldToFilter')->willReturnSelf();
        $this->assertFalse($this->repository->hasLines(500));
    }

    public function testSaveForOrderReplacesOnEveryCall(): void
    {
        $calls = [];
        $this->resource->expects($this->exactly(2))->method('replaceForOrder')
            ->willReturnCallback(function (int $id, array $rows) use (&$calls): void {
                $calls[] = [$id, count($rows)];
            });

        $this->repository->saveForOrder(500, [['kind' => 'coupon'], ['kind' => 'automatic']]);
        $this->repository->saveForOrder(500, [['kind' => 'coupon']]);

        $this->assertSame([[500, 2], [500, 1]], $calls);
    }
}
