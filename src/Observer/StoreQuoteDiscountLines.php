<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteLineStoreInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores the discount lines on the discount-carrying quote address while the totals are collected.
 *
 * The lines are saved with the quote by core's normal flow; this observer never saves and never throws.
 */
class StoreQuoteDiscountLines implements ObserverInterface
{
    /**
     * @param ConfigInterface $config
     * @param QuoteDiscountLinesInterface $quoteDiscountLines
     * @param QuoteLineStoreInterface $store
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly QuoteDiscountLinesInterface $quoteDiscountLines,
        private readonly QuoteLineStoreInterface $store,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        try {
            /** @var Quote $quote */
            $quote = $observer->getEvent()->getData('quote');
            /** @var ShippingAssignmentInterface $assignment */
            $assignment = $observer->getEvent()->getData('shipping_assignment');
            /** @var Address $address */
            $address = $assignment->getShipping()->getAddress();
            if (!$this->config->isEnabled((int)$quote->getStoreId())) {
                $this->store->store($address, []);

                return;
            }
            $carrier = $quote->isVirtual() ? Address::ADDRESS_TYPE_BILLING : Address::ADDRESS_TYPE_SHIPPING;
            if ($address->getAddressType() !== $carrier) {
                return;
            }
            $this->store->store($address, $this->quoteDiscountLines->getLinesForAddress($address));
        } catch (Throwable $exception) {
            $this->logger->error(
                'Discount lines could not be stored on the quote address: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }
    }
}
