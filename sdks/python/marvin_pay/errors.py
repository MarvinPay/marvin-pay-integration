"""Error types for the Marvin Pay Python SDK."""

from __future__ import annotations


class MarvinPayError(Exception):
    """Raised when a Marvin Pay API call fails.

    Attributes:
        message: Human-readable error message. When the API returned a body with
            a ``message`` field, that value is used.
        status_code: The HTTP status code of the failing response, or ``None`` for
            client-side / transport failures and timeouts.
        body: The parsed response body (``dict``/``list``) when JSON, otherwise the
            raw text, or ``None`` when there was no body.
    """

    def __init__(self, message, status_code=None, body=None):
        super().__init__(message)
        self.message = message
        self.status_code = status_code
        self.body = body

    def __str__(self):
        if self.status_code is not None:
            return f"[HTTP {self.status_code}] {self.message}"
        return str(self.message)

    def __repr__(self):
        return (
            f"MarvinPayError(message={self.message!r}, "
            f"status_code={self.status_code!r})"
        )
