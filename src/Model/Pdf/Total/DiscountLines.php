<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model\Pdf\Total;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;
use Magento\Tax\Helper\Data;
use Magento\Tax\Model\Calculation;
use Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\DiscountLineRepositoryInterface;
use PixelPerfect\DiscountLines\Api\Service\DocumentLineLabelInterface;
use PixelPerfect\DiscountLines\Api\Service\SalesDiscountLinesInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * PDF discount total: one entry per stored discount line, the core entry otherwise.
 */
class DiscountLines extends DefaultTotal
{
    /**
     * @param Data $taxHelper
     * @param Calculation $taxCalculation
     * @param CollectionFactory $ordersFactory
     * @param ConfigInterface $config
     * @param DiscountLineRepositoryInterface $repository
     * @param SalesDiscountLinesInterface $salesLines
     * @param DocumentLineLabelInterface $labels
     * @param LoggerInterface $logger
     * @param array<string, mixed> $data
     */
    public function __construct(
        Data $taxHelper,
        Calculation $taxCalculation,
        CollectionFactory $ordersFactory,
        private readonly ConfigInterface $config,
        private readonly DiscountLineRepositoryInterface $repository,
        private readonly SalesDiscountLinesInterface $salesLines,
        private readonly DocumentLineLabelInterface $labels,
        private readonly LoggerInterface $logger,
        array $data = []
    ) {
        parent::__construct($taxHelper, $taxCalculation, $ordersFactory, $data);
    }

    /**
     * @inheritDoc
     *
     * @return array<int, array{amount: string, label: string, font_size: int}>
     */
    public function getTotalsForDisplay()
    {
        try {
            $source = $this->getSource();
            $orderId = (int)$source->getOrderId();
            if ($this->config->isEnabled((int)$source->getStoreId()) && $this->repository->hasLines($orderId)) {
                $lines = $this->salesLines->getLines($source);
                if ($lines !== []) {
                    $fontSize = $this->getFontSize() ? $this->getFontSize() : 7;
                    $entries = [];
                    foreach ($lines as $line) {
                        $entries[] = [
                            'amount' => $this->getOrder()->formatPriceTxt($this->labels->value($line)),
                            'label' => $this->labels->format($line) . ':',
                            'font_size' => $fontSize,
                        ];
                    }

                    return $entries;
                }
                // Stored lines but none for this document: core amount, never the rule description in the label.
                $this->setData('title_source_field', null);
            }
        } catch (Throwable $exception) {
            $this->logger->error('Discount lines: PDF totals failed, core entry kept.', [
                'exception' => $exception,
            ]);
        }

        return parent::getTotalsForDisplay();
    }
}
