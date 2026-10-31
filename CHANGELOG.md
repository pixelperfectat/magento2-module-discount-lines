# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Module skeleton and store-view scoped configuration (enabled, display variant, email icon).
- Per-rule discount lines resolved at totals collect time and stored on the quote address; a generic rule resolver
  plus free shipping in an extensible composite; label rule that never exposes rule descriptions or names.
- Discount lines on the cart `discount` total segment (`extension_attributes.discount_lines`), a ViewModel for
  storefront renderers, a neutral default stylesheet with CSS custom properties and tag / percent / gift icons.
- Persistence of per-rule order and item amounts in `pp_discount_line` at order placement, with label snapshots.
- One totals row per rule (`pp_discount_line_<n>`) in order, invoice and credit memo totals (emails, My Account,
  admin) and PDF totals; proportional split for invoices and credit memos that always matches the document discount.
- Translations `en_US` and `de_DE`.

### Changed

- The cart `discount` segment title no longer carries the rule description.
- Lines that do not add up to the discount total are never shown (core row with the plain title instead); stored
  order lines are scaled to the order discount; a failed collect clears the stored quote lines.
- Discounted orders without attributable lines store one generic "Discount" line; documents without a share of the
  order lines show the core amount with the plain label "Discount".
- PDFs print "Gift" / "Free" for zero-amount gift and free shipping lines; coupon free shipping lines are labelled
  "Coupon CODE"; gift lines with a coupon code are removable.
- Requires `magento/module-store` and `magento/module-tax` instead of `magento/module-checkout`.
