"""Collect from a customer, then wait for the outcome.

Runs a mobile-money collect (payer -> merchant) and polls until the transaction
reaches a terminal state.

Config is read from the environment:
    MARVIN_API_KEY      (required)  your merchant API key
    MARVIN_BASE_URL     (optional)  defaults to production
    MARVIN_COUNTRY      (optional)  ISO-3166 alpha-2, default "CM"
    MARVIN_CURRENCY     (optional)  ISO-4217, default "XAF"
    MARVIN_PAYMENT_METHOD (optional) provider name, default "mtn_cm"
    MARVIN_MOBILE_NUMBER (optional) payer number (test number provided by Marvin Pay)
    MARVIN_AMOUNT       (optional)  whole number, default 5000

Usage:
    export MARVIN_API_KEY=...        # or set it in your shell / .env
    python collect.py
"""

import os
import sys
import time

from marvin_pay import MarvinPayClient, MarvinPayError, normalize_status


def main():
    api_key = os.environ.get("MARVIN_API_KEY")
    if not api_key:
        sys.exit("Set MARVIN_API_KEY (see .env.example).")

    base_url = os.environ.get(
        "MARVIN_BASE_URL", "https://api.marvincorporate.co/api"
    )
    client = MarvinPayClient(api_key=api_key, base_url=base_url)

    # Your own unique reference for this transaction.
    transaction_id = f"order-{int(time.time())}"

    payment_request = {
        # Currency and country ALWAYS travel together.
        "country_code": os.environ.get("MARVIN_COUNTRY", "CM"),
        "currency": os.environ.get("MARVIN_CURRENCY", "XAF"),
        # Whole number only (XAF/XOF have no minor units), range 100..500000.
        "amount": int(os.environ.get("MARVIN_AMOUNT", "5000")),
        "mobile_number": os.environ.get("MARVIN_MOBILE_NUMBER", "<your-test-msisdn>"),
        "payment_method": os.environ.get("MARVIN_PAYMENT_METHOD", "mtn_cm"),
        "transaction_id": transaction_id,
        "description": "SDK example collect",
        # "customer_email": "payer@example.com",  # optional: sends a receipt
        # "fee_bearer": "CUSTOMER",               # optional: MERCHANT (default)
    }

    try:
        # X-Idempotency-Key defaults to the transaction_id.
        result = client.collect(payment_request)
    except MarvinPayError as exc:
        sys.exit(f"collect failed: HTTP {exc.status_code} {exc.message}")

    print("Collect initiated:")
    print(f"  transaction_id     : {result.get('transaction_id')}")
    print(f"  message            : {result.get('message')}")
    print(f"  transaction_status : {result.get('transaction_status')}")
    print("  (a 200/202 does NOT mean money moved — confirming below)")

    if normalize_status(result.get("transaction_status")) in ("SUCCEEDED", "FAILED"):
        return

    print("\nWaiting for a terminal status (this can take a minute)...")
    try:
        final = client.wait_for_completion(transaction_id)
    except MarvinPayError as exc:
        sys.exit(f"still pending: {exc.message}")

    print(f"Final status: {normalize_status(final.get('transaction_status'))}")
    print(f"Raw: {final}")


if __name__ == "__main__":
    main()
