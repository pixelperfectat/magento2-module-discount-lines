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
            $this->cleanShippingLabel($subject, $result);
            $hasRow = $this->hasDiscountRow($result);
            if (!$hasRow && !$this->mayInsert($area)) {
                return $result;
            }
            $lines = $this->loadLines($subject);
            if ($lines === null) {
                return $result;
            }
            if ($lines === []) {
                // Stored lines, but none for this document (e.g. a shipping-only refund): keep the core amount,
                // never its label, which carries the rule description.
                return $hasRow ? $this->relabel($result) : $result;
            }
            if (!$hasRow) {
                return $this->insertRows($subject, $result, $lines);
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
     * Remove the rule description core appends to the shipping label of documents without a discount row.
     *
     * Core (`Totals::addShippingTotal`) labels shipping "Shipping & Handling (<discount description>)" when the
     * document has no discount row and no coupon code, which is the case for zero-discount gift or free shipping
     * orders and their invoices and credit memos. A coupon code in the label is safe and kept.
     *
     * @param Totals $block
     * @param array<int|string, DataObject> $totals
     * @return void
     */
    private function cleanShippingLabel(Totals $block, array $totals): void
    {
        /** @var AbstractModel|null $source Order, invoice or credit memo depending on the block subclass */
        $source = $block->getSource();
        if ($source === null || !$this->config->isEnabled((int)$source->getStoreId())) {
            return;
        }
        $description = (string)$source->getData('discount_description');
        if ($description === '') {
            return;
        }
        $plain = (string)__('Shipping & Handling');
        foreach ($totals as $total) {
            if ($total->getCode() === 'shipping' && (string)$total->getLabel() === $plain . ' (' . $description . ')') {
                $total->setData('label', $plain);
            }
        }
    }

    /**
     * Rows may only be inserted into the default area: admin renders 'footer' and '' separately.
     *
     * @param array<int|string, mixed>|string|null $area
     * @return bool
     */
    private function mayInsert($area): bool
    {
        return $area === null || $area === '';
    }

    /**
     * Insert the rows when core produced no discount row because the document discount is 0 (gift or free shipping).
     *
     * @param Totals $block
     * @param array<int|string, DataObject> $totals
     * @param DiscountLineInterface[] $lines
     * @return array<int|string, DataObject>
     */
    private function insertRows(Totals $block, array $totals, array $lines): array
    {
        foreach ($lines as $line) {
            if (abs($line->getAmount()) > 0.00001 || abs($line->getBaseAmount()) > 0.00001) {
                return $totals;
            }
        }
        $rows = $this->buildRows($lines);
        /** @var AbstractModel|null $source Order, invoice or credit memo depending on the block subclass */
        $source = $block->getSource();
        if ($source === null || abs((float)$source->getData('discount_amount')) > 0.00001) {
            return $totals;
        }

        $rebuilt = [];
        $inserted = false;
        foreach ($totals as $key => $total) {
            if (!$inserted && in_array($total->getCode(), ['tax', 'grand_total'], true)) {
                $rebuilt = $rebuilt + $rows;
                $inserted = true;
            }
            $rebuilt[$key] = $total;
        }

        return $inserted ? $rebuilt : $totals + $rows;
    }

    /**
     * Whether the totals contain the core discount row.
     *
     * @param array<int|string, DataObject> $totals
     * @return bool
     */
    private function hasDiscountRow(array $totals): bool
    {
        foreach ($totals as $total) {
            if ($total->getCode() === 'discount') {
                return true;
            }
        }

        return false;
    }

    /**
     * Give the core discount row the plain label, value unchanged.
     *
     * @param array<int|string, DataObject> $totals
     * @return array<int|string, DataObject>
     */
    private function relabel(array $totals): array
    {
        foreach ($totals as $total) {
            if ($total->getCode() === 'discount') {
                $total->setData('label', (string)__('Discount'));
            }
        }

        return $totals;
    }

    /**
     * Load the lines for the block source.
     *
     * Null when the module is off or the order has no stored lines (core row untouched), an empty array when the
     * order has lines but the document gets none.
     *
     * @param Totals $block
     * @return DiscountLineInterface[]|null
     */
    private function loadLines(Totals $block): ?array
    {
        /** @var AbstractModel|null $source Order, invoice or credit memo depending on the block subclass */
        $source = $block->getSource();
        if ($source === null || !$this->config->isEnabled((int)$source->getStoreId())) {
            return null;
        }
        $orderId = (int)($source instanceof Order ? $source->getId() : $source->getOrderId());
        if ($orderId <= 0 || !$this->repository->hasLines($orderId)) {
            return null;
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
     * Rebuild the totals with the rows in place of the core discount row.
     *
     * Results without a discount row (e.g. the admin footer area) are returned unchanged.
     *
     * @param array<int|string, DataObject> $totals
     * @param array<string, DataObject> $rows
     * @return array<int|string, DataObject>
     */
    private function rebuild(array $totals, array $rows): array
    {
        $found = false;
        foreach ($totals as $total) {
            $found = $found || $total->getCode() === 'discount';
        }
        if (!$found) {
            return $totals;
        }

        $rebuilt = [];
        foreach ($totals as $key => $total) {
            if ($total->getCode() === 'discount') {
                $rebuilt = $rebuilt + $rows;
                continue;
            }
            $rebuilt[$key] = $total;
        }

        return $rebuilt;
    }
}
