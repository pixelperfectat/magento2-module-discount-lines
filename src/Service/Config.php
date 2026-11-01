<?php declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PixelPerfect\DiscountLines\Api\ConfigInterface;

readonly class Config implements ConfigInterface
{
    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritDoc
     */
    public function getVariant(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_VARIANT, ScopeInterface::SCOPE_STORE, $storeId);

        return is_string($value) && in_array($value, self::VARIANTS, true) ? $value : self::DEFAULT_VARIANT;
    }

    /**
     * @inheritDoc
     */
    public function isEmailIconEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_EMAIL_ICON, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
