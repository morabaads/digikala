=== DK Bridge — WooCommerce to Digikala ===
Contributors: morabaads
Tags: woocommerce, digikala, marketplace, sync, iran
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish WooCommerce products to the Digikala Seller Center via the official Open API, auto-fix content to Digikala's rules, and keep stock, prices and orders in sync.

== Description ==

* Connects with a seller self-token or a registered OAuth app (tokens are stored encrypted).
* Rule engine with 40+ content checks and one-click auto-fix (titles, English titles, descriptions, pros/cons, attributes, dimensions, images, MEFA).
* Readiness score that mirrors Digikala's own content score.
* Image processing: square white canvas, trimming, transparency flattening, JPG conversion, size limits, plus Digikala's AI image check.
* Duplicate detection: sell on an existing Digikala product instead of creating a duplicate.
* Resumable pipeline: draft → attributes → title → images → save → moderation → variants (colors/sizes).
* Smart pricing: Toman→Rial, markup, commission compensation, rounding, allowed price boundary.
* Stock & price sync with the Batch API, oversell buffer, order import that decreases WooCommerce stock.
* Webhooks, email notifications, dry-run mode, WP-CLI, structured logs.

See docs/PRD.md for the full product requirements document (Persian).

== Changelog ==

= 1.0.0 =
* First release.
