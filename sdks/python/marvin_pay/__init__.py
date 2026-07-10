"""Marvin Pay — Python SDK.

A lightweight client for the Marvin Pay mobile-money payment gateway (West &
Central Africa; XAF / XOF). See ``CONTRACT.md`` for the authoritative API
reference this SDK is generated from.

Quickstart::

    from marvin_pay import MarvinPayClient, normalize_status

    client = MarvinPayClient(api_key="YOUR_API_KEY")
    result = client.collect({
        "country_code": "CM",
        "currency": "XAF",
        "amount": 5000,
        "mobile_number": "237670000001",
        "payment_method": "mtn_cm",
        "transaction_id": "order-1001",
    })
    final = client.wait_for_completion("order-1001")
    print(normalize_status(final["transaction_status"]))
"""

from .client import MarvinPayClient, normalize_status
from .errors import MarvinPayError
from .webhook import parse_event, verify_signature

__all__ = [
    "MarvinPayClient",
    "MarvinPayError",
    "normalize_status",
    "verify_signature",
    "parse_event",
]

__version__ = "0.1.0"
