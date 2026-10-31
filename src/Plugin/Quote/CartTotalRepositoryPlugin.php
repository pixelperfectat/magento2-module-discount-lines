<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Plugin\Quote;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartTotalRepositoryInterface;
use Magento\Quote\Api\Data\TotalSegmentExtensionFactory;
use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Api\Data\TotalSegmentInterfaceFactory;
use Magento\Quote\Api\Data\TotalsInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
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
    private const TOTALS_SORT_PATH = 'sales/totals_sort';

    /**
     * @param CartRepositoryInterface $cartRepository
     * @param ConfigInterface $config
     * @param QuoteLineStoreInterface $store
     * @param TotalSegmentExtensionFactory $extensionFactory
     * @param LoggerInterface $logger
     * @param TotalSegmentInterfaceFactory $segmentFactory
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ConfigInterface $config,
        private readonly QuoteLineStoreInterface $store,
        private readonly TotalSegmentExtensionFactory $extensionFactory,
        private readonly LoggerInterface $logger,
        private readonly TotalSegmentInterfaceFactory $segmentFactory,
        private readonly ScopeConfigInterface $scopeConfig
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

        try {
            $quote = $this->cartRepository->getActive((int)$cartId);
            if (!$quote instanceof Quote || !$this->config->isEnabled((int)$quote->getStoreId())) {
                return $result;
            }
            $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
            $lines = $this->store->load($address);
            if ($segment === null) {
                // Core adds no discount segment while the discount is 0 (gift or free shipping only).
                $this->addMissingSegment($result, $lines, (int)$quote->getStoreId());

                return $result;
            }
            if ($lines === []) {
                // Never let the core title through: it carries the rule description.
                $segment->setTitle((string)__('Discount'));

                return $result;
            }
            $this->decorate($segment, $lines);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Discount lines could not be added to the cart totals: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
        }

        return $result;
    }

    /**
     * Attach the lines and the safe title to a discount segment.
     *
     * @param TotalSegmentInterface $segment
     * @param DiscountLineInterface[] $lines
     * @return void
     */
    private function decorate(TotalSegmentInterface $segment, array $lines): void
    {
        $extension = $segment->getExtensionAttributes() ?? $this->extensionFactory->create();
        // The extension class is generated at runtime from extension_attributes.xml.
        // @phpstan-ignore-next-line
        $extension->setDiscountLines($lines);
        $segment->setExtensionAttributes($extension);
        // The core title carries the rule description, which must never reach the customer.
        $segment->setTitle((string)__('Discount (%1)', $this->labels($lines)));
    }

    /**
     * Insert a zero discount segment carrying the lines where core would have placed it.
     *
     * @param TotalsInterface $totals
     * @param DiscountLineInterface[] $lines
     * @param int $storeId
     * @return void
     */
    private function addMissingSegment(TotalsInterface $totals, array $lines, int $storeId): void
    {
        if ($lines === [] || !$this->allZero($lines)) {
            return;
        }
        $segment = $this->segmentFactory->create();
        $segment->setCode(self::SEGMENT_CODE);
        $segment->setValue(0.0);
        $this->decorate($segment, $lines);

        $existing = $totals->getTotalSegments();
        $position = $this->insertionPosition(array_keys($existing), $storeId);
        $totals->setTotalSegments(
            array_slice($existing, 0, $position, true)
            + [self::SEGMENT_CODE => $segment]
            + array_slice($existing, $position, null, true)
        );
    }

    /**
     * Whether every line is a zero line (gift, free shipping); a money line means core output is left alone.
     *
     * @param DiscountLineInterface[] $lines
     * @return bool
     */
    private function allZero(array $lines): bool
    {
        foreach ($lines as $line) {
            if (abs($line->getAmount()) > 0.00001 || abs($line->getBaseAmount()) > 0.00001) {
                return false;
            }
        }

        return true;
    }

    /**
     * Index at which the discount segment is inserted: before the first segment that sorts after it.
     *
     * Uses the configured totals order, falls back to before the shipping segment, otherwise appends.
     *
     * @param array<int, int|string> $codes
     * @param int $storeId
     * @return int
     */
    private function insertionPosition(array $codes, int $storeId): int
    {
        $sort = $this->scopeConfig->getValue(self::TOTALS_SORT_PATH, ScopeInterface::SCOPE_STORE, $storeId);
        $sort = is_array($sort) ? $sort : [];
        $own = isset($sort[self::SEGMENT_CODE]) ? (int)$sort[self::SEGMENT_CODE] : null;
        if ($own !== null) {
            foreach ($codes as $index => $code) {
                if (isset($sort[$code]) && (int)$sort[$code] > $own) {
                    return $index;
                }
            }
        }
        $shipping = array_search('shipping', $codes, true);

        return $shipping !== false ? (int)$shipping : count($codes);
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
