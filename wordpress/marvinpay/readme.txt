=== Marvin Pay ===
Contributors: marvincorporate
Tags: payments, mobile money, woocommerce, gateway, africa
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept mobile-money payments through Marvin Pay — WooCommerce gateway plus pay-button, donation, inline-form and payment-status widgets.

== Description ==

Marvin Pay lets your WordPress site accept mobile-money payments (MTN, Orange,
Moov, Free Money, T-Money and more) across Cameroon, Gabon, Côte d'Ivoire,
Senegal, Benin, Togo and Mali.

* **WooCommerce gateway** — classic and block checkout. The customer confirms
  the payment on their phone from your order-received page.
* **Pay Button** — a fixed-amount payment button anywhere shortcodes or blocks
  work: `[marvinpay_button amount="5000" description="Consultation"]`
* **Donation form** — visitor-chosen amount with optional presets:
  `[marvinpay_donation presets="1000,5000,10000" min="500"]`
* **Inline form** — a full payment form embedded in any page:
  `[marvinpay_form]`
* **Payment status** — a receipt widget for your thank-you page:
  `[marvinpay_status]` (reads the `?mp_ref=` link parameter)

Amounts are whole units (XAF/XOF), 100–500,000 per transaction. Outcomes are
confirmed against the Marvin Pay status API and reconciled automatically —
webhooks are supported (with HMAC signatures) but never blindly trusted.

== Installation ==

1. Upload the plugin zip via Plugins → Add New → Upload, and activate it.
2. Open Settings → Marvin Pay: paste your API key, pick your country, save.
3. Copy the webhook URL shown there into your Marvin Pay merchant portal.
4. Drop a widget on any page, or enable Marvin Pay in WooCommerce → Settings
   → Payments.

== Frequently Asked Questions ==

= Which currencies are supported? =
XAF and XOF. The currency follows the country you select — they always travel
together. Your WooCommerce store currency must match.

= Do I need WooCommerce? =
No. The widgets work on any WordPress site. WooCommerce is auto-detected.

= Why does my pay button show "reload the page"? =
Full-page caches can serve stale payment forms. Exclude pages containing
Marvin Pay widgets from your page cache.

== Changelog ==

= 1.0.0 =
* Initial release: WooCommerce gateway (classic + blocks), four widgets,
  webhook receiver with signature verification, automatic reconciliation.
