# Discount Lines for Magento 2

Replaces the single combined "Discount" totals row with one line per discount source
(coupon, automatic rule, gift, free shipping) across cart, checkout and sales documents.

## Requirements

- PHP 8.3+
- Magento 2.4.7+ / Mage-OS

## Installation

```bash
composer require pixelperfectat/magento2-module-discount-lines
bin/magento setup:upgrade
```

## Configuration

Stores > Configuration > Sales > Discount Lines (store-view scope):

| Path | Default | Description |
|---|---|---|
| `pp_discount_lines/general/enabled` | `1` | Master switch |
| `pp_discount_lines/display/variant` | `B` | Storefront variant `A`, `B`, `C` or `D` |
| `pp_discount_lines/documents/email_icon` | `0` | Small icon next to discount lines in emails |

## License

MIT
