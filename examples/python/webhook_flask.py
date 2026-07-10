"""Receive Marvin Pay webhooks with a tiny Flask app.

Demonstrates the recommended verify -> CONFIRM -> dedupe -> 200 flow.

IMPORTANT (contract §8.5): Marvin Pay signs webhook deliveries with an HMAC-SHA256
signature in the `X-Webhook-Signature` header when your account has a webhook secret
configured. Because deliveries are at-least-once, the real trust anchor is still
confirming the transaction out-of-band via `get_status`. This example always does that.

Flask is a DEV-ONLY example dependency (it is NOT a dependency of the SDK):
    pip install flask

Config is read from the environment:
    MARVIN_API_KEY        (required)  used to confirm via get_status
    MARVIN_BASE_URL       (optional)  defaults to production
    MARVIN_WEBHOOK_SECRET (optional)  your webhook secret (enables signed deliveries)
    PORT                  (optional)  default 5000

Usage:
    export MARVIN_API_KEY=...
    python webhook_flask.py
    # then point your merchant account's webhookUrl at http(s)://<host>/webhooks/marvin
"""

import os
import sys

try:
    from flask import Flask, request
except ImportError:
    sys.exit("This example needs Flask (dev-only): pip install flask")

from marvin_pay import MarvinPayClient, MarvinPayError, parse_event, verify_signature

API_KEY = os.environ.get("MARVIN_API_KEY")
if not API_KEY:
    sys.exit("Set MARVIN_API_KEY (see .env.example).")

BASE_URL = os.environ.get("MARVIN_BASE_URL", "https://api.marvincorporate.co/api")
WEBHOOK_SECRET = os.environ.get("MARVIN_WEBHOOK_SECRET", "")

client = MarvinPayClient(api_key=API_KEY, base_url=BASE_URL)
app = Flask(__name__)

# Dedupe store: (transactionId, status). In production use a durable store
# (Redis / a DB), not an in-memory set that resets on restart.
_seen = set()


@app.post("/webhooks/marvin")
def marvin_webhook():
    # 1. Read the EXACT raw bytes (needed for signature verification; never
    #    re-serialize parsed JSON before verifying).
    raw_body = request.get_data()

    # 2. Verify the signature. It returns False when the secret/signature is
    #    missing, so we do NOT reject on a failed check — we rely on step 4 instead.
    signature = request.headers.get("X-Webhook-Signature")
    signature_ok = verify_signature(raw_body, signature, WEBHOOK_SECRET)
    app.logger.info("signature present/valid: %s", signature_ok)

    # 3. Parse the payload.
    try:
        event = parse_event(raw_body)
    except ValueError:
        # Malformed body — ack with 400 so it is not retried forever.
        return {"error": "invalid json"}, 400

    transaction_id = event.get("transactionId")
    reported_status = event.get("status")
    if not transaction_id:
        return {"error": "missing transactionId"}, 400

    # 4. Dedupe on transactionId + status (events may be delivered more than once).
    dedupe_key = (transaction_id, reported_status)
    if dedupe_key in _seen:
        app.logger.info("duplicate webhook ignored: %s", dedupe_key)
        return {"status": "duplicate-ignored"}, 200
    _seen.add(dedupe_key)

    # 5. CONFIRM out-of-band before acting — this is the real trust anchor.
    try:
        confirmed = client.get_status(transaction_id)
    except MarvinPayError as exc:
        app.logger.error("confirm failed for %s: %s", transaction_id, exc)
        # Return non-2xx so Marvin Pay retries the delivery later.
        return {"error": "confirm failed"}, 503

    confirmed_status = confirmed.get("transaction_status")
    app.logger.info(
        "webhook %s reported=%s confirmed=%s",
        transaction_id, reported_status, confirmed_status,
    )

    # 6. Act on the CONFIRMED status (fulfill order, credit user, ...).
    #    e.g. if normalize_status(confirmed_status) == "SUCCEEDED": fulfill(...)

    # 7. Return 2xx quickly so the delivery is marked delivered.
    return {"status": "ok"}, 200


if __name__ == "__main__":
    port = int(os.environ.get("PORT", "5000"))
    # Dev server only. Use a real WSGI server (gunicorn/uwsgi) behind HTTPS in prod.
    app.run(host="0.0.0.0", port=port)
