"""Pay a public invoice by reference (hosted-pay flow).

A merchant creates the invoice (portal / JWT); a customer pays it by reference on
a PUBLIC endpoint — no API key is required by the server for `pay_invoice`. This
is useful if you build your own pay page instead of using the hosted one.

After paying, poll the PUBLIC status endpoint (`get_qr_status`, which the invoice
and campaign pay pages also use) until the transaction is terminal.

Config is read from the environment:
    MARVIN_BASE_URL          (optional)  defaults to production
    MARVIN_API_KEY           (optional)  not required for public pay
    MARVIN_INVOICE_REFERENCE (optional)  the invoice reference (or pass as arg 1)
    MARVIN_COUNTRY           (optional)  ISO-3166 alpha-2, default "CM"
    MARVIN_CURRENCY          (optional)  ISO-4217, default "XAF"
    MARVIN_PAYMENT_METHOD    (optional)  provider name, default "mtn_cm"
    MARVIN_MOBILE_NUMBER     (optional)  payer number, default "237670000001"

Usage:
    python pay_invoice.py <invoice_reference>
"""

import os
import sys
import time

from marvin_pay import MarvinPayClient, MarvinPayError, normalize_status


def main():
    reference = None
    if len(sys.argv) > 1:
        reference = sys.argv[1]
    reference = reference or os.environ.get("MARVIN_INVOICE_REFERENCE")
    if not reference:
        sys.exit("Usage: python pay_invoice.py <invoice_reference>")

    base_url = os.environ.get(
        "MARVIN_BASE_URL", "https://api.marvincorporate.co/api"
    )
    # api_key is optional for public pay; passing "" simply omits the header.
    client = MarvinPayClient(
        api_key=os.environ.get("MARVIN_API_KEY", ""), base_url=base_url
    )

    # PayInvoiceRequest — the amount is fixed by the invoice (do not send it).
    pay_request = {
        "country_code": os.environ.get("MARVIN_COUNTRY", "CM"),
        "currency": os.environ.get("MARVIN_CURRENCY", "XAF"),
        "mobile_number": os.environ.get("MARVIN_MOBILE_NUMBER", "237670000001"),
        "payment_method": os.environ.get("MARVIN_PAYMENT_METHOD", "mtn_cm"),
        "beneficiary_name": os.environ.get("MARVIN_BENEFICIARY", "John Payer"),
        # "customer_email": "payer@example.com",  # optional: sends a receipt
    }

    try:
        result = client.pay_invoice(reference, pay_request)
    except MarvinPayError as exc:
        sys.exit(f"pay_invoice failed: HTTP {exc.status_code} {exc.message}")

    print("Payment initiated:")
    print(f"  transaction_id     : {result.get('transaction_id')}")
    print(f"  transaction_status : {result.get('transaction_status')}")

    transaction_id = result.get("transaction_id")
    if not transaction_id:
        return

    # Public poll (~every 5s) — the invoice/campaign pay pages use this endpoint.
    print("\nPolling public status...")
    for _ in range(12):  # ~1 minute of polling for the demo
        try:
            public = client.get_qr_status(transaction_id)
        except MarvinPayError as exc:
            print(f"  poll error: {exc.message}")
            break
        normalized = normalize_status(public.get("status"))
        print(f"  status={public.get('status')} -> {normalized}")
        if normalized in ("SUCCEEDED", "FAILED"):
            break
        time.sleep(5)


if __name__ == "__main__":
    main()
