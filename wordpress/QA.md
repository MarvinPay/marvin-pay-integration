# Marvin Pay WordPress Plugin — Manual QA

Run against the **test environment** (base URL from Marvin Pay support) with
sandbox numbers (docs/SANDBOX.md in the marvin_pay_api repo):

| Number         | Behaviour                    |
|----------------|------------------------------|
| 237600000001   | Instant SUCCESS              |
| 237600000002   | Instant FAILED               |
| 237600000003   | SUCCESS after ~5 min         |
| 237600000004   | FAILED after ~5 min          |

Environment: WordPress 6.x + WooCommerce 8.x (e.g. `wp-env` or a staging
site), plugin settings → mode=Test, test base URL + test API key, country=CM.
For the block-checkout case use a BLOCK theme (e.g. Twenty Twenty-Four) —
**release gate:** if the payment widget does not appear on the
order-received page under the block theme's Order Confirmation template, a
fallback must be built before release.

## Widgets
- [ ] `[marvinpay_button amount="5000"]` renders; pay with …0001 → success pane + reference.
- [ ] Same button with …0002 → failure pane; Try again produces a NEW reference (never reuses the failed one).
- [ ] Same button with …0003 → waiting pane persists; poll resolves to success within ~5–6 min (backoff visible in the network tab: 5s, 10s, 20s, 40s, 60s…).
- [ ] Donation widget enforces min/max and presets fill the amount field.
- [ ] Inline form renders without modal; submit works end-to-end.
- [ ] Tampering: edit `data-config` amount in devtools → initiate returns "form has expired".
- [ ] Rate limit: 6th initiation within 10 min → friendly "too many attempts".
- [ ] Success page redirect: configure a success page containing `[marvinpay_status]`; after success, browser lands there and the receipt shows masked mobile + amount + reference, and NO payer name/email.
- [ ] Gutenberg: insert each of the 4 blocks; editor preview renders; front-end matches shortcode output.
- [ ] Idempotent double-submit: double-click Pay in the modal → exactly ONE transaction row and one charge (server throttle + single-modal guard).

## WooCommerce
- [ ] Gateway hidden when store currency ≠ XAF; visible when XAF (country=CM).
- [ ] Classic checkout: place order → order-received page auto-opens the modal → …0001 → order becomes Processing; order note carries the wpmp_ reference.
- [ ] Block checkout: Marvin Pay appears as a payment option; same flow works.
- [ ] Fail path: …0002 → order becomes Failed.
- [ ] Non-integer total (e.g. 5000.50) → gateway errors at checkout with the whole-amount message.
- [ ] Multi-attempt: fail an attempt (…0002), retry with …0001 → order becomes Processing; then confirm the earlier FAILED attempt resolving (or a replayed failed webhook) does NOT flip the paid order to Failed (order notes show "no status change").

## Webhook + reconciliation
- [ ] With a webhook secret configured on both sides: sandbox success fires a webhook → row + order update even with the browser closed.
- [ ] Invalid signature (curl a forged body) → HTTP 400.
- [ ] Unknown transactionId (forged but validly signed) → HTTP 200, handled:false.
- [ ] Delayed success with browser closed → cron (≤5 min after the 5-min flip) resolves the row and order. Trigger manually: `wp cron event run marvinpay_reconcile`.
- [ ] Idempotency: replay the same webhook twice → second delivery is a no-op (row already terminal).

## Settings
- [ ] Connection test button reports the provider list.
- [ ] Saved API key displays masked and survives an unrelated settings save.
- [ ] Switching country invalidates the cached provider list.
- [ ] Test mode with empty test base URL shows the warning and blocks payment.
