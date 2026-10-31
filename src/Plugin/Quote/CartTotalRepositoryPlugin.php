<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Plugin\Quote;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\Service\QuoteDiscountLinesInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exposes the itemised discount lines on the `discount` total segment of the cart totals.
 *
 * Covers every consumer of the cart totals: REST (`/V1/carts/.../totals`), the checkout config of the cart page and
 * the Hyva Checkout price summary. Never breaks the cart: on any failure the totals are returned unchanged.
 */
class CartTotalRepositoryPlugin
{
    public const SEGMENT_CODE = 'discount';
    private const EPSILON = 0.00001;

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteDiscountLinesInterface $quoteLines
     * @param TotalSegmentExtensionFactory $extensionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteDiscountLinesInterface $quoteLines,
        private readonly TotalSegmentExtensionFactory $extensionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add the discount lines to the discount segment.
     *
     * @param CartTotalRepositoryInterface $subject
     * @param TotalsInterface $result
     * @param int|string $cartId
     * @return TotalsInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(CartTotalRepositoryInterface $subject, TotalsInterface $result, $cartId): TotalsInterface
    {
        $segment = $result->getTotalSegments()[self::SEGMENT_CODE] ?? null;
        if ($segment === null) {
            return $result;
        }

        try {
            $quote = $this->cartRepository->getActive((int)$cartId);
            if ($quote instanceof Quote) {
                $this->ensureRuleBreakdown($quote);
            }
            $lines = $quote instanceof Quote ? $this->quoteLines->getLines($quote) : [];
            if ($lines === []) {
                return $result;
            }
            $extension = $segment->getExtensionAttributes() ?? $this->extensionFactory->create();
            // The extension class is generated at runtime from extension_attributes.xml.
            // @phpstan-ignore-next-line
            $extension->setDiscountLines($lines);
            $segment->setExtensionAttributes($extension);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Discount lines could not be added to the cart totals: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $result;
    }

    /**
     * Collect the totals when the loaded quote lacks the per-rule breakdown.
     *
     * The breakdown (`discounts` on the address extension attributes) only exists in the request that collected the
     * totals; a quote freshly loaded from the database (REST totals, cart page) has the discount amount but no
     * breakdown. Collecting is what every cart action does anyway and yields the same amounts.
     *
     * @param Quote $quote
     * @return void
     */
    private function ensureRuleBreakdown(Quote $quote): void
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
        if (abs((float)$address->getDiscountAmount()) < self::EPSILON) {
            return;
        }
        $extension = $address->getExtensionAttributes();
        // The extension interface is generated at runtime and unknown to static analysis.
        // @phpstan-ignore-next-line
        if ($extension === null || !$extension->getDiscounts()) {
            $quote->collectTotals();
        }
    }
}
