<?php declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Api;

interface ConfigInterface
{
    public const XML_PATH_ENABLED = 'pp_discount_lines/general/enabled';
    public const XML_PATH_VARIANT = 'pp_discount_lines/display/variant';
    public const XML_PATH_EMAIL_ICON = 'pp_discount_lines/documents/email_icon';

    public const VARIANTS = ['A', 'B', 'C', 'D'];
    public const DEFAULT_VARIANT = 'B';

    /**
     * Master switch for itemised discount lines
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool;

    /**
     * Storefront display variant (A, B, C or D); invalid values fall back to B
     *
     * @param int|null $storeId
     * @return string
     */
    public function getVariant(?int $storeId = null): string;

    /**
     * Whether emails show a small icon next to discount lines
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEmailIconEnabled(?int $storeId = null): bool;
}
