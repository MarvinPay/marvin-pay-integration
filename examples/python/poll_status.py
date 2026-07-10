"""Check (and optionally wait on) a transaction's status.

`GET /v1/payment/status/{transactionId}` is the authoritative way to confirm a
transaction — use it to resolve pending collects/payouts and to confirm webhooks.

Config is read from the environment:
    MARVIN_API_KEY   (required)  your merchant API key
    MARVIN_BASE_URL  (optional)  defaults to production

Usage:
    python poll_status.py <transaction_id> [--wait]

    <transaction_id>   the reference you passed on collect/payout
    --wait             block and poll until a terminal state (else check once)
"""

import os
import sys

from marvin_pay import MarvinPayClient, MarvinPayError, normalize_status


def main():
    args = [a for a in sys.argv[1:]]
    wait = "--wait" in args
    positional = [a for a in args if not a.startswith("--")]
    if not positional:
        sys.exit("Usage: python poll_status.py <transaction_id> [--wait]")
    transaction_id = positional[0]

    api_key = os.environ.get("MARVIN_API_KEY")
    if not api_key:
        sys.exit("Set MARVIN_API_KEY (see .env.example).")

    base_url = os.environ.get(
        "MARVIN_BASE_URL", "https://api.marvincorporate.co/api"
    )
    client = MarvinPayClient(api_key=api_key, base_url=base_url)

    try:
        if wait:
            print(f"Polling {transaction_id} until terminal...")
            status = client.wait_for_completion(transaction_id)
        else:
            status = client.get_status(transaction_id)
    except MarvinPayError as exc:
        sys.exit(f"status check failed: HTTP {exc.status_code} {exc.message}")

    print(f"transaction_status : {status.get('transaction_status')}")
    print(f"normalized         : {normalize_status(status.get('transaction_status'))}")
    print(f"raw                : {status}")


if __name__ == "__main__":
    main()
