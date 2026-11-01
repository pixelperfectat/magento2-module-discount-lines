# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [1.0.1] - 2026-11-01

### Fixed

- The admin help text of the display variant no longer mentions a cart drawer: the variants apply to the cart page and checkout (9b8b0c0).

## [1.0.0] - 2026-11-01

### Added

- One discount line per sales rule (coupon, automatic, gift, free shipping) instead of Magento's single merged discount row, with display variants A to D per store view.
- Discount lines in the cart and checkout totals data (`extension_attributes.discount_lines` on the `discount` segment), a ViewModel for storefront renderers and a neutral default stylesheet with CSS custom properties and icons.
- Per-rule amounts stored per order and item at order placement (`pp_discount_line`); invoices and credit memos split each rule in proportion.
- One totals row per rule in order, invoice and credit memo totals: emails, My Account, admin and PDFs.
- Safety rules on every surface: the lines always add up to Magento's discount, otherwise the core row shows with the plain label "Discount"; a rule's description never reaches the customer, including the suffix core appends to the shipping label.
- Extension point for adapters: a composite of line resolvers configured in `di.xml`.
- Translations `en_US` and `de_DE`.
