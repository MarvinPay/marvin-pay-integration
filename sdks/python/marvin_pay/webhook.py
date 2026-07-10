"""Webhook helpers for Marvin Pay.

.. note::

    Marvin Pay signs webhook deliveries with an HMAC-SHA256 signature in the
    ``X-Webhook-Signature`` header (format ``sha256=<hex>``) when your account
    has a webhook secret configured. Verify it against your webhook secret with
    ``verify_signature``.

    Regardless of the signature, and because deliveries are at-least-once,
    **always confirm every webhook out-of-band** with
    ``MarvinPayClient.get_status(transaction_id)`` before acting on it
    (fulfilling orders, crediting users, etc.), and **dedupe on
    ``transactionId`` + ``status``** because the same event may be delivered
    more than once.

    ``verify_signature`` computes ``HMAC-SHA256(secret, raw_body)`` as lowercase
    hex and compares it in constant time against the header value with the
    ``sha256=`` prefix stripped.
"""

from __future__ import annotations

import hashlib
import hmac
import json

__all__ = ["verify_signature", "parse_event"]

_SIGNATURE_PREFIX = "sha256="


def _as_bytes(value):
    if isinstance(value, (bytes, bytearray)):
        return bytes(value)
    if isinstance(value, str):
        return value.encode("utf-8")
    raise TypeError(f"expected bytes or str, got {type(value).__name__}")


def verify_signature(raw_body, signature_header, secret):
    """Verify a webhook's HMAC-SHA256 signature (intended scheme).

    Computes ``HMAC-SHA256(secret, raw_body)`` as lowercase hex and compares it,
    in constant time, against ``signature_header`` with any ``sha256=`` prefix
    stripped.

    .. note::

        When ``signature_header`` is missing/empty or ``secret`` is falsy this
        returns ``False`` — so do not gate acceptance solely on this. Always
        confirm via ``get_status`` (deliveries are at-least-once).

    Args:
        raw_body: The exact raw request body (``bytes`` or ``str``). Must be the
            unparsed bytes as received — re-serializing parsed JSON can change
            bytes and break the HMAC.
        signature_header: The ``X-Webhook-Signature`` header value, or ``None``.
        secret: Your webhook secret.

    Returns:
        ``True`` only if a valid signature is present and matches; ``False``
        otherwise.
    """
    if not signature_header or not secret:
        return False

    body_bytes = _as_bytes(raw_body)
    secret_bytes = _as_bytes(secret)

    provided = signature_header.strip()
    if provided.lower().startswith(_SIGNATURE_PREFIX):
        provided = provided[len(_SIGNATURE_PREFIX):]
    provided = provided.strip().lower()

    expected = hmac.new(secret_bytes, body_bytes, hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, provided)


def parse_event(raw_body):
    """Parse a webhook payload into a dict.

    Args:
        raw_body: The raw request body (``bytes`` or ``str``).

    Returns:
        The decoded JSON object (a dict). Fields include ``event``,
        ``transactionId``, ``status`` (SUCCESS/FAILED/PENDING/CANCEL), ``amount``,
        ``currency`` and the direction-aware amount split (contract §8.4).

    Raises:
        json.JSONDecodeError: If the body is not valid JSON.
    """
    if isinstance(raw_body, (bytes, bytearray)):
        raw_body = raw_body.decode("utf-8")
    return json.loads(raw_body)
