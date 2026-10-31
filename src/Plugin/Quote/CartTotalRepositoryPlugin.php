<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Plugin\Quote;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\QuoteLineStoreInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exposes the stored discount lines on the `discount` total segment of the cart totals.
 *
 * Covers every consumer of the cart totals: REST (`/V1/carts/.../totals`), the checkout config of the cart page and
 * the Hyva Checkout price summary. Read-only: the lines were stored at collect time, nothing is collected or saved
 * here. Never breaks the cart: on any failure the totals are returned unchanged.
 */
class CartTotalRepositoryPlugin
{
    public const SEGMENT_CODE = 'discount';

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param ConfigInterface $config
     * @param QuoteLineStoreInterface $store
     * @param TotalSegmentExtensionFactory $extensionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ConfigInterface $config,
        private readonly QuoteLineStoreInterface $store,
        private readonly TotalSegmentExtensionFactory $extensionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Add the discount lines to the discount segment and replace its title with the safe labels.
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
            if (!$quote instanceof Quote || !$this->config->isEnabled((int)$quote->getStoreId())) {
                return $result;
            }
            $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
            $lines = $this->store->load($address);
            if ($lines === []) {
                return $result;
            }
            $extension = $segment->getExtensionAttributes() ?? $this->extensionFactory->create();
            // The extension class is generated at runtime from extension_attributes.xml.
            // @phpstan-ignore-next-line
            $extension->setDiscountLines($lines);
            $segment->setExtensionAttributes($extension);
            // The core title carries the rule description, which must never reach the customer.
            $segment->setTitle((string)__('Discount (%1)', $this->labels($lines)));
        } catch (Throwable $exception) {
            $this->logger->error(
                'Discount lines could not be added to the cart totals: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $result;
    }

    /**
     * Join the customer-safe labels; coupons show their code.
     *
     * @param DiscountLineInterface[] $lines
     * @return string
     */
    private function labels(array $lines): string
    {
        $labels = [];
        foreach ($lines as $line) {
            $labels[] = $line->getKind() === DiscountLineInterface::KIND_COUPON && $line->getCode() !== null
                ? $line->getCode()
                : $line->getLabel();
        }

        return implode(', ', $labels);
    }
}
