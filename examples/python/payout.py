"""Pay out to a recipient, then wait for the outcome.

Runs a mobile-money payout (merchant -> recipient) and polls until terminal.

Config is read from the environment:
    MARVIN_API_KEY      (required)  your merchant API key
    MARVIN_BASE_URL     (optional)  defaults to production
    MARVIN_COUNTRY      (optional)  ISO-3166 alpha-2, default "CM"
    MARVIN_CURRENCY     (optional)  ISO-4217, default "XAF"
    MARVIN_PAYMENT_METHOD (optional) provider name, default "mtn_cm"
    MARVIN_MOBILE_NUMBER (optional) recipient number (test number provided by Marvin Pay)
    MARVIN_AMOUNT       (optional)  whole number, default 5000

Usage:
    export MARVIN_API_KEY=...
    python payout.py
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

    transaction_id = f"payout-{int(time.time())}"

    payment_request = {
        "country_code": os.environ.get("MARVIN_COUNTRY", "CM"),
        "currency": os.environ.get("MARVIN_CURRENCY", "XAF"),
        "amount": int(os.environ.get("MARVIN_AMOUNT", "5000")),
        # For a payout, mobile_number / beneficiary_name identify the RECIPIENT.
        "mobile_number": os.environ.get("MARVIN_MOBILE_NUMBER", "<your-test-msisdn>"),
        "beneficiary_name": os.environ.get("MARVIN_BENEFICIARY", "Jane Doe"),
        "payment_method": os.environ.get("MARVIN_PAYMENT_METHOD", "mtn_cm"),
        "transaction_id": transaction_id,
        "description": "SDK example payout",
        # "fee_bearer": "CUSTOMER",  # optional: nets the fee out of the amount
    }

    try:
        result = client.payout(payment_request)
    except MarvinPayError as exc:
        sys.exit(f"payout failed: HTTP {exc.status_code} {exc.message}")

    print("Payout initiated:")
    print(f"  transaction_id     : {result.get('transaction_id')}")
    print(f"  message            : {result.get('message')}")
    print(f"  transaction_status : {result.get('transaction_status')}")

    if normalize_status(result.get("transaction_status")) in ("SUCCEEDED", "FAILED"):
        return

    print("\nWaiting for a terminal status...")
    try:
        final = client.wait_for_completion(transaction_id)
    except MarvinPayError as exc:
        sys.exit(f"still pending: {exc.message}")

    print(f"Final status: {normalize_status(final.get('transaction_status'))}")
    print(f"Raw: {final}")


if __name__ == "__main__":
    main()
