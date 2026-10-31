<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Plugin\Sales\Block\Order;

use Magento\Framework\DataObject;
use Magento\Sales\Block\Order\Totals;
use Magento\Sales\Model\AbstractModel;
use Magento\Sales\Model\Order;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\DocumentLineLabelInterface;
use PixelPerfect\DiscountLines\Api\Service\SalesDiscountLinesInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Replaces the merged core `discount` totals row with one row per stored discount line.
 */
class TotalsPlugin
{
    public const ROW_CODE_PREFIX = 'pp_discount_line_';

    /**
     * @param ConfigInterface $config
     * @param DiscountLineRepositoryInterface $repository
     * @param SalesDiscountLinesInterface $salesLines
     * @param DocumentLineLabelInterface $labels
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly DiscountLineRepositoryInterface $repository,
        private readonly SalesDiscountLinesInterface $salesLines,
        private readonly DocumentLineLabelInterface $labels,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Swap the discount row for one row per line.
     *
     * @param Totals $subject
     * @param array<int|string, DataObject>|null $result Null while the block is not initialised
     * @param array<int|string, mixed>|string|null $area
     * @return array<int|string, DataObject>|null
     */
    public function afterGetTotals(Totals $subject, $result, $area = null)
    {
        if (!is_array($result)) {
            return $result;
        }
        try {
            $lines = $this->loadLines($subject);
            if ($lines === []) {
                return $result;
            }

            return $this->rebuild($result, $this->buildRows($lines));
        } catch (Throwable $exception) {
            $this->logger->error('Discount lines: totals rendering failed, core rows kept.', [
                'exception' => $exception,
            ]);

            return $result;
        }
    }

    /**
     * Load the lines for the block source, empty when the module is off or nothing is stored.
     *
     * @param Totals $block
     * @return DiscountLineInterface[]
     */
    private function loadLines(Totals $block): array
    {
        /** @var AbstractModel|null $source Order, invoice or credit memo depending on the block subclass */
        $source = $block->getSource();
        if ($source === null || !$this->config->isEnabled((int)$source->getStoreId())) {
            return [];
        }
        $orderId = (int)($source instanceof Order ? $source->getId() : $source->getOrderId());
        if ($orderId <= 0 || !$this->repository->hasLines($orderId)) {
            return [];
        }

        return $this->salesLines->getLines($source);
    }

    /**
     * Build the totals rows.
     *
     * @param DiscountLineInterface[] $lines
     * @return array<string, DataObject>
     */
    private function buildRows(array $lines): array
    {
        $rows = [];
        foreach (array_values($lines) as $position => $line) {
            $code = self::ROW_CODE_PREFIX . $position;
            $rows[$code] = new DataObject([
                'code' => $code,
                'value' => $this->labels->value($line),
                'base_value' => $this->labels->value($line, true),
                'label' => $this->labels->format($line),
                'discount_line' => $line->toArray(),
            ]);
        }

        return $rows;
    }

    /**
     * Rebuild the ordered totals with the rows in place of the discount row.
     *
     * @param array<int|string, DataObject> $totals
     * @param array<string, DataObject> $rows
     * @return array<int|string, DataObject>
     */
    private function rebuild(array $totals, array $rows): array
    {
        $hasDiscount = false;
        foreach ($totals as $total) {
            $hasDiscount = $hasDiscount || $total->getCode() === 'discount';
        }

        $rebuilt = [];
        $inserted = false;
        foreach ($totals as $key => $total) {
            $code = (string)$total->getCode();
            $insertHere = $hasDiscount
                ? $code === 'discount'
                : in_array($code, ['shipping', 'grand_total'], true);
            if ($insertHere && !$inserted) {
                $rebuilt = $rebuilt + $rows;
                $inserted = true;
            }
            if ($code === 'discount') {
                continue;
            }
            $rebuilt[$key] = $total;
        }

        return $inserted ? $rebuilt : $totals;
    }
}
