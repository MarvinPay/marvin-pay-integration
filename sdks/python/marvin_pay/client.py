"""Marvin Pay HTTP client.

A thin, dependency-light wrapper over the Marvin Pay payment gateway REST API.
Every endpoint, field, header and enum used here is defined in the authoritative
API contract (``CONTRACT.md``). Nothing is invented.

Key facts baked into this client (see the contract for detail):

- Auth is by ``X-API-KEY`` for the ``/v1/payment/**`` surface.
- Currency and country always travel together on every money request.
- Amounts are whole numbers (XAF/XOF have no minor units); range 100..500000.
- Mobile-money collects/payouts are asynchronous: a 200/202 does NOT mean money
  moved. Confirm the outcome via ``get_status`` / ``wait_for_completion``.
- REST responses report ``transaction_status`` as SUCCESSFUL/FAILED/PENDING;
  webhooks report ``status`` as SUCCESS/FAILED/PENDING/CANCEL. Use
  ``normalize_status`` to collapse both into a single enum.
"""

from __future__ import annotations

import time
from decimal import Decimal, InvalidOperation
from urllib.parse import quote

import requests

from .errors import MarvinPayError

__all__ = ["MarvinPayClient", "normalize_status"]

DEFAULT_BASE_URL = "https://api.marvincorporate.co/api"

# Normalized, SDK-facing status values (contract §2.1).
STATUS_SUCCEEDED = "SUCCEEDED"
STATUS_FAILED = "FAILED"
STATUS_PENDING = "PENDING"
STATUS_CANCELLED = "CANCELLED"
STATUS_UNKNOWN = "UNKNOWN"

# Maps every wire spelling (REST + webhook) to the normalized value.
_STATUS_MAP = {
    "SUCCESSFUL": STATUS_SUCCEEDED,
    "SUCCESS": STATUS_SUCCEEDED,
    "SUCCEEDED": STATUS_SUCCEEDED,
    "FAILED": STATUS_FAILED,
    "FAIL": STATUS_FAILED,
    "PENDING": STATUS_PENDING,
    "CANCEL": STATUS_CANCELLED,
    "CANCELLED": STATUS_CANCELLED,
    "CANCELED": STATUS_CANCELLED,
}

_TERMINAL = frozenset((STATUS_SUCCEEDED, STATUS_FAILED))


def normalize_status(status):
    """Collapse any Marvin Pay status spelling into a single enum.

    REST ``transaction_status`` says ``SUCCESSFUL`` while webhook ``status`` says
    ``SUCCESS``; both map to ``SUCCEEDED`` here. ``CANCEL`` maps to ``CANCELLED``.
    Unknown / missing values map to ``UNKNOWN``.

    Args:
        status: A status string from a REST response or webhook payload.

    Returns:
        One of ``SUCCEEDED`` / ``FAILED`` / ``PENDING`` / ``CANCELLED`` /
        ``UNKNOWN``.
    """
    if status is None:
        return STATUS_UNKNOWN
    return _STATUS_MAP.get(str(status).strip().upper(), STATUS_UNKNOWN)


def _to_whole_number(amount):
    """Coerce an amount to a whole-number ``int``.

    XAF/XOF have no minor units, so a fractional amount is a bug. Accepts
    ``int`` / ``float`` / ``str`` / ``Decimal`` representing a whole number and
    raises ``ValueError`` otherwise.
    """
    if isinstance(amount, bool):
        raise ValueError("amount must be a number, not a bool")
    if isinstance(amount, int):
        return amount
    try:
        value = Decimal(str(amount))
    except (InvalidOperation, ValueError, TypeError) as exc:
        raise ValueError(f"amount is not a valid number: {amount!r}") from exc
    if value != value.to_integral_value():
        raise ValueError(
            f"amount must be a whole number (XAF/XOF have no minor units): {amount!r}"
        )
    return int(value)


def _is_retryable(status_code):
    """Retry 429 (rate limited) and any 5xx (contract §11)."""
    return status_code == 429 or 500 <= status_code <= 599


def _retry_delay(response, default=1.0):
    """Honour ``Retry-After`` when present, else a small fixed backoff."""
    retry_after = response.headers.get("Retry-After")
    if retry_after:
        try:
            return max(0.0, float(retry_after))
        except ValueError:
            return default
    return default


class MarvinPayClient:
    """Client for the Marvin Pay payment gateway.

    Args:
        api_key: Merchant API key sent as ``X-API-KEY`` on ``/v1/payment/**``.
            May be empty for purely public "hosted pay" flows.
        base_url: API base URL, including the ``/api`` context path. Defaults to
            production. Use ``http://localhost:9090/api`` for local dev.
        timeout: Per-request timeout in seconds.
    """

    def __init__(
        self,
        api_key,
        base_url=DEFAULT_BASE_URL,
        timeout=30,
    ):
        self.api_key = api_key
        self.base_url = (base_url or DEFAULT_BASE_URL).rstrip("/")
        self.timeout = timeout
        self._session = requests.Session()
        # Response headers from the most recent call. After collect/payout, look
        # here for X-Idempotency-Replay / X-Idempotency-Key-Auto (contract §7).
        self.last_response_headers = {}

    # ------------------------------------------------------------------ #
    # Core payment endpoints (X-API-KEY)                                  #
    # ------------------------------------------------------------------ #

    def collect(self, payment_request, idempotency_key=None):
        """Collect from a customer (payer -> merchant).

        ``POST /v1/payment/collect``. Mobile-money collects are asynchronous:
        expect ``transaction_status == "PENDING"`` and then confirm via
        ``wait_for_completion`` / ``get_status``.

        Args:
            payment_request: A ``PaymentRequest`` dict. Required snake_case
                fields: ``country_code``, ``currency``, ``amount``,
                ``mobile_number``, ``payment_method``, ``transaction_id``.
                Optional: ``beneficiary_name``, ``description``,
                ``customer_email``, ``fee_bearer`` (MERCHANT/CUSTOMER).
            idempotency_key: Optional. Sent as ``X-Idempotency-Key``. Defaults to
                ``payment_request['transaction_id']``.

        Returns:
            The ``PaymentResult`` dict.
        """
        return self._collect_or_payout(
            "/v1/payment/collect", payment_request, idempotency_key
        )

    def payout(self, payment_request, idempotency_key=None):
        """Pay out to a recipient (merchant -> recipient).

        ``POST /v1/payment/payout``. Same body/response as ``collect``; here
        ``beneficiary_name`` / ``mobile_number`` identify the recipient.
        ``fee_bearer=CUSTOMER`` nets the fee out of ``amount``.

        Args:
            payment_request: A ``PaymentRequest`` dict (see ``collect``).
            idempotency_key: Optional; defaults to the ``transaction_id``.

        Returns:
            The ``PaymentResult`` dict.
        """
        return self._collect_or_payout(
            "/v1/payment/payout", payment_request, idempotency_key
        )

    def _collect_or_payout(self, path, payment_request, idempotency_key):
        body = self._prepare_body(payment_request)
        key = idempotency_key or payment_request.get("transaction_id")
        headers = {}
        if key is not None:
            # Always send X-Idempotency-Key when we can (contract §7).
            headers["X-Idempotency-Key"] = str(key)
        return self._request(
            "POST", path, json_body=body, extra_headers=headers
        )

    def get_status(self, transaction_id):
        """Fetch the authoritative status of a transaction.

        ``GET /v1/payment/status/{transactionId}``. This is the source of truth
        for confirming both polling and webhooks.

        Returns:
            A ``TransactionStatusResponse`` dict (includes ``transaction_status``).
        """
        path = "/v1/payment/status/" + quote(str(transaction_id), safe="")
        return self._request("GET", path, allow_retry=True)

    def get_fees(self, currency, amount, direction, fee_bearer=None):
        """Estimate fees for a prospective transaction.

        ``GET /v1/payment/fees``.

        Args:
            currency: ISO-4217 (``XAF`` / ``XOF``).
            amount: Whole-number amount.
            direction: ``COLLECT`` or ``PAYOUT`` (merchants); also
                ``TOPUP`` / ``WITHDRAWAL`` exist server-side.
            fee_bearer: Optional ``MERCHANT`` / ``CUSTOMER``.

        Returns:
            A ``FeeEstimateResponse`` dict.
        """
        params = {
            "currency": currency,
            "amount": _to_whole_number(amount),
            "direction": direction,
        }
        if fee_bearer is not None:
            params["fee_bearer"] = fee_bearer
        return self._request(
            "GET", "/v1/payment/fees", params=params, allow_retry=True
        )

    def get_payment_methods(self, country_code):
        """List the mobile-money providers valid for a country.

        ``GET /v1/payment/payment-methods/{countryCode}``. The returned strings
        are the values you pass as ``payment_method``. Always fetch this at
        runtime rather than hard-coding.

        Returns:
            A list of provider-name strings.
        """
        path = "/v1/payment/payment-methods/" + quote(str(country_code), safe="")
        return self._request("GET", path, allow_retry=True)

    def wait_for_completion(
        self, transaction_id, start_delay=5, max_delay=60, timeout=600
    ):
        """Poll ``get_status`` until the transaction reaches a terminal state.

        Implements the contract's recommended schedule (§6): wait ``start_delay``
        seconds, poll, then exponential backoff capped at ``max_delay``, giving up
        after ``timeout`` seconds.

        Args:
            transaction_id: Your transaction reference.
            start_delay: Seconds to wait before the first poll (default 5).
            max_delay: Backoff cap in seconds (default 60).
            timeout: Total budget in seconds before giving up (default 600).

        Returns:
            The final ``TransactionStatusResponse`` dict when the normalized
            status is ``SUCCEEDED`` or ``FAILED``.

        Raises:
            MarvinPayError: If ``timeout`` elapses while still pending.
        """
        deadline = time.monotonic() + timeout
        delay = start_delay
        while True:
            now = time.monotonic()
            if now >= deadline:
                break
            time.sleep(min(delay, deadline - now))
            status = self.get_status(transaction_id)
            if normalize_status(status.get("transaction_status")) in _TERMINAL:
                return status
            delay = min(delay * 2, max_delay)
        raise MarvinPayError(
            f"Timed out after {timeout}s waiting for transaction "
            f"{transaction_id!r} to reach a terminal state; still pending.",
            status_code=None,
            body=None,
        )

    # ------------------------------------------------------------------ #
    # Internals                                                           #
    # ------------------------------------------------------------------ #

    def _prepare_body(self, body):
        """Shallow-copy a request body and coerce ``amount`` to a whole number."""
        if body is None:
            return None
        prepared = dict(body)
        if prepared.get("amount") is not None:
            prepared["amount"] = _to_whole_number(prepared["amount"])
        return prepared

    def _base_headers(self):
        headers = {
            "Accept": "application/json",
            "User-Agent": "marvinpay-python/0.1.0",
        }
        if self.api_key:
            headers["X-API-KEY"] = self.api_key
        return headers

    def _request(
        self,
        method,
        path,
        params=None,
        json_body=None,
        extra_headers=None,
        allow_retry=False,
    ):
        url = self.base_url + path
        headers = self._base_headers()
        if extra_headers:
            headers.update(extra_headers)

        # Retry once on 429/5xx for idempotent GETs only (contract §11).
        max_attempts = 2 if allow_retry else 1
        for attempt in range(max_attempts):
            try:
                response = self._session.request(
                    method,
                    url,
                    params=params,
                    json=json_body,
                    headers=headers,
                    timeout=self.timeout,
                )
            except requests.RequestException as exc:
                raise MarvinPayError(
                    f"Request to {url} failed: {exc}",
                    status_code=None,
                    body=None,
                ) from exc

            self.last_response_headers = dict(response.headers)

            if (
                _is_retryable(response.status_code)
                and attempt < max_attempts - 1
            ):
                time.sleep(_retry_delay(response))
                continue

            return self._parse(response)

        # Unreachable: the loop always returns or raises.
        raise MarvinPayError("Request failed unexpectedly", status_code=None)

    def _parse(self, response):
        body = self._read_body(response)
        if 200 <= response.status_code < 300:
            return body
        raise MarvinPayError(
            self._error_message(body, response),
            status_code=response.status_code,
            body=body,
        )

    @staticmethod
    def _read_body(response):
        if not response.content:
            return None
        try:
            return response.json()
        except ValueError:
            return response.text

    @staticmethod
    def _error_message(body, response):
        if isinstance(body, dict):
            for field in ("message", "error", "detail"):
                value = body.get(field)
                if value:
                    return str(value)
        if isinstance(body, str) and body.strip():
            return body.strip()
        return f"HTTP {response.status_code} {response.reason or ''}".strip()
