# WordPress Plugin

The official Marvin Pay WordPress plugin turns any WordPress site into a
mobile-money-accepting site in minutes — with or without WooCommerce.

**Download:** grab `marvinpay-wordpress-<version>.zip` from the
[Plugins page](https://app.marvincorporate.co/plugins), or build it from this
repo: `bash wordpress/build.sh` → `wordpress/dist/`.

## Install & configure

1. WordPress admin → Plugins → Add New → Upload Plugin → select the zip → Activate.
2. Settings → Marvin Pay:
   - **Mode** — Live, or Test (test base URL + credentials come from Marvin Pay
     support; see [Testing & Sandbox](11-testing-and-sandbox.md)).
   - **API key** — from your merchant portal (sent as `X-API-KEY`).
   - **Country** — one of CM, GA, CI, SN, BJ, TG, ML. The currency follows the
     country automatically ([currency & country travel together](10-reference.md#currencies--countries)).
3. Copy the **Webhook URL** shown on that page into your merchant portal
   (Account → webhook URL), and optionally set a **webhook secret** on both
   sides for signed deliveries ([Webhooks](08-webhooks.md)).
4. Click **Test connection** — you should see your country's provider list.

## Widgets (shortcodes and Gutenberg blocks)

| Widget | Shortcode | Block |
|---|---|---|
| Fixed-amount pay button | `[marvinpay_button amount="5000" description="Consultation" button_text="Pay"]` | Marvin Pay Button |
| Donation / custom amount | `[marvinpay_donation presets="1000,5000,10000" min="500" max="100000"]` | Marvin Pay Donation |
| Inline payment form | `[marvinpay_form amount="" description="" show_name="1" show_email="1"]` | Marvin Pay Form |
| Payment status / receipt | `[marvinpay_status]` | Marvin Pay Status |

Amounts are whole units, 100–500,000 ([amount rules](10-reference.md#amount-rules)).
The status widget reads the `?mp_ref=<reference>` URL parameter — put it on the
page you configured as the plugin's "success page".

## WooCommerce

Enable **Marvin Pay** under WooCommerce → Settings → Payments. Both the classic
and the block checkout are supported. The store currency must equal your
account country's currency (XAF or XOF) and order totals must be whole
amounts. After placing the order the customer confirms the charge on their
phone from the order-received page; the order then moves to *Processing*
(success) or *Failed*.

## How outcomes are confirmed

The plugin follows the integration contract end to end:

- every collect carries an `X-Idempotency-Key` ([Idempotency](06-idempotency.md));
- the browser polls on the contract schedule — 5s first, ×2 backoff capped at
  60s, 10-minute budget ([Transaction Status](05-transaction-status.md));
- webhooks are verified (HMAC-SHA256) and **always re-confirmed** against the
  status endpoint before acting ([Webhooks](08-webhooks.md));
- a WP-Cron reconciler re-checks pending transactions every 5 minutes for up
  to 24 hours, so an outcome is never lost even if the payer closes the tab.

## Caching caveat

Payment forms carry a nonce and a signed configuration. Exclude pages that
contain Marvin Pay widgets from full-page caching, or payers may see
"this payment form has expired".

## Rate-limit & proxy caveat

The plugin throttles payment initiations per client IP (5 per 10 minutes)
using the server's `REMOTE_ADDR`. Behind a reverse proxy or CDN
(Cloudflare, etc.) every visitor shares the proxy's IP unless your server
restores the real client address (e.g. `mod_remoteip` / `set_real_ip_from`)
— configure that, or busy stores may throttle legitimate shoppers.
Mobile-money customers are also frequently behind carrier NAT, so several
distinct shoppers can legitimately share one IP.
