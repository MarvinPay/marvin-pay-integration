'use strict';

/**
 * Marvin Pay — Node.js SDK (single-file, zero runtime dependencies).
 *
 * Requires Node 18+ (uses the built-in global `fetch`).
 *
 * This client is a faithful, thin wrapper over the Marvin Pay HTTP API as
 * described in CONTRACT.md (the source of truth). It does not invent any
 * endpoint, header, field, or enum that is not in the contract.
 *
 * Key facts encoded here (see CONTRACT.md for detail):
 *   - Servlet context path is `/api`; the default baseUrl already includes it.
 *   - Payment API auth is the `X-API-KEY` header. Portal endpoints use a JWT
 *     `Authorization: Bearer` token (optional here via `bearerToken`).
 *   - Money-moving POSTs (collect/payout) take an `X-Idempotency-Key`.
 *   - Amounts are WHOLE numbers (XAF/XOF have no minor units), 100–500000.
 *   - REST status responses say `SUCCESSFUL`; webhooks say `SUCCESS`. Use
 *     `normalizeStatus()` to collapse both into one enum.
 *
 * Usage:
 *   const { MarvinPayClient, MarvinPayError } = require('./marvin-pay.js');
 *   const client = new MarvinPayClient({ apiKey: process.env.MARVIN_API_KEY });
 */

const DEFAULT_BASE_URL = 'https://api.marvincorporate.co/api';

/**
 * Error thrown for any non-2xx HTTP response, network failure, or timeout.
 * Carries the HTTP status (0 for transport-level errors), the parsed response
 * body (when available), and an optional machine-readable `code`.
 */
class MarvinPayError extends Error {
  constructor(message, { httpStatus = 0, body = null, code } = {}) {
    super(message);
    this.name = 'MarvinPayError';
    this.httpStatus = httpStatus;
    this.body = body;
    if (code) this.code = code;
  }
}

/** Resolve after `ms` milliseconds. */
function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Coerce a caller-supplied amount into a whole number.
 * The API rejects fractional amounts (XAF/XOF have no minor units), so we
 * round to the nearest integer before serializing.
 */
function toWholeNumber(value) {
  const n = typeof value === 'string' ? Number(value) : value;
  if (typeof n !== 'number' || !Number.isFinite(n)) {
    throw new MarvinPayError(`amount must be a finite number, got: ${JSON.stringify(value)}`, {
      code: 'INVALID_AMOUNT',
    });
  }
  return Math.round(n);
}

class MarvinPayClient {
  /**
   * @param {object} [options]
   * @param {string} [options.apiKey]       Merchant API key, sent as `X-API-KEY`.
   * @param {string} [options.baseUrl]      Defaults to production; must include `/api`.
   * @param {string} [options.bearerToken]  Optional portal JWT for `Authorization: Bearer`.
   * @param {number} [options.timeoutMs]    Per-request timeout (default 30000).
   */
  constructor({ apiKey, baseUrl = DEFAULT_BASE_URL, bearerToken, timeoutMs = 30000 } = {}) {
    this.apiKey = apiKey;
    this.bearerToken = bearerToken;
    this.timeoutMs = timeoutMs;
    // Strip any trailing slash so path concatenation is predictable.
    this.baseUrl = String(baseUrl).replace(/\/+$/, '');
  }

  // ---------------------------------------------------------------------------
  // Core payment API (X-API-KEY)
  // ---------------------------------------------------------------------------

  /**
   * Collect from a customer (payer → merchant). POST /v1/payment/collect.
   * Always sends `X-Idempotency-Key` — defaults to `paymentRequest.transaction_id`.
   * Mobile-money collects are usually async: expect `PENDING`, then confirm via
   * `waitForCompletion` / `getStatus` (or a webhook).
   *
   * @param {object} paymentRequest  snake_case PaymentRequest (see CONTRACT §3.1)
   * @param {object} [opts]
   * @param {string} [opts.idempotencyKey]
   * @returns {Promise<object>} PaymentResult
   */
  async collect(paymentRequest, { idempotencyKey } = {}) {
    const body = this._preparePaymentRequest(paymentRequest);
    const key = idempotencyKey || body.transaction_id;
    const { data, response } = await this._request('POST', '/v1/payment/collect', {
      body,
      idempotencyKey: key,
      retry: false, // never auto-retry a money-moving POST
    });
    this._attachIdempotency(data, response);
    return data;
  }

  /**
   * Pay out (merchant → recipient). POST /v1/payment/payout.
   * Same body/response as collect; here mobile_number/beneficiary_name identify
   * the recipient. Always sends `X-Idempotency-Key`.
   *
   * @param {object} paymentRequest  snake_case PaymentRequest (see CONTRACT §3.2)
   * @param {object} [opts]
   * @param {string} [opts.idempotencyKey]
   * @returns {Promise<object>} PaymentResult
   */
  async payout(paymentRequest, { idempotencyKey } = {}) {
    const body = this._preparePaymentRequest(paymentRequest);
    const key = idempotencyKey || body.transaction_id;
    const { data, response } = await this._request('POST', '/v1/payment/payout', {
      body,
      idempotencyKey: key,
      retry: false,
    });
    this._attachIdempotency(data, response);
    return data;
  }

  /**
   * Authoritative status check. GET /v1/payment/status/{transactionId}.
   * @param {string} transactionId  YOUR transaction_id.
   * @returns {Promise<object>} TransactionStatusResponse
   */
  async getStatus(transactionId) {
    const { data } = await this._request(
      'GET',
      `/v1/payment/status/${encodeURIComponent(transactionId)}`,
    );
    return data;
  }

  /**
   * Fee estimate. GET /v1/payment/fees.
   * @param {object} params
   * @param {string} params.currency
   * @param {number} [params.amount]
   * @param {('COLLECT'|'PAYOUT'|'TOPUP'|'WITHDRAWAL')} params.direction
   * @param {('MERCHANT'|'CUSTOMER')} [params.feeBearer]
   * @returns {Promise<object>} FeeEstimateResponse
   */
  async getFees({ currency, amount, direction, feeBearer } = {}) {
    const query = { currency, direction };
    if (amount !== undefined && amount !== null) query.amount = toWholeNumber(amount);
    // The contract's query parameter is snake_case: `fee_bearer`.
    if (feeBearer !== undefined && feeBearer !== null) query.fee_bearer = feeBearer;
    const { data } = await this._request('GET', '/v1/payment/fees', { query });
    return data;
  }

  /**
   * List the provider names valid for a country (the values you pass as
   * `payment_method`). GET /v1/payment/payment-methods/{countryCode}.
   * @param {string} countryCode  ISO-3166 alpha-2, e.g. "CM".
   * @returns {Promise<string[]>}
   */
  async getPaymentMethods(countryCode) {
    const { data } = await this._request(
      'GET',
      `/v1/payment/payment-methods/${encodeURIComponent(countryCode)}`,
    );
    return data;
  }

  /**
   * Poll status until the transaction reaches a terminal state.
   * Schedule (per CONTRACT §6): wait `startDelayMs`, then exponential backoff
   * capped at `maxDelayMs`, giving up after `timeoutMs`.
   *
   * Resolves with the final TransactionStatusResponse once `transaction_status`
   * is SUCCESSFUL/FAILED (also treats CANCELLED as terminal). Throws a
   * MarvinPayError with code `TIMEOUT` if still PENDING after `timeoutMs`.
   *
   * @param {string} transactionId
   * @param {object} [opts]
   * @param {number} [opts.startDelayMs=5000]
   * @param {number} [opts.maxDelayMs=60000]
   * @param {number} [opts.timeoutMs=600000]
   * @returns {Promise<object>} the final TransactionStatusResponse
   */
  async waitForCompletion(
    transactionId,
    { startDelayMs = 5000, maxDelayMs = 60000, timeoutMs = 600000 } = {},
  ) {
    const deadline = Date.now() + timeoutMs;
    let delay = startDelayMs;

    // eslint-disable-next-line no-constant-condition
    while (true) {
      const remaining = deadline - Date.now();
      if (remaining <= 0) {
        throw new MarvinPayError(
          `Timed out after ${timeoutMs}ms waiting for ${transactionId} (still PENDING)`,
          { code: 'TIMEOUT' },
        );
      }
      // Never sleep past the deadline.
      await sleep(Math.min(delay, remaining));

      const status = await this.getStatus(transactionId);
      const normalized = MarvinPayClient.normalizeStatus(status && status.transaction_status);
      if (normalized === 'SUCCEEDED' || normalized === 'FAILED' || normalized === 'CANCELLED') {
        return status;
      }

      delay = Math.min(delay * 2, maxDelayMs);
    }
  }

  // ---------------------------------------------------------------------------
  // Public "hosted pay" flows (NO auth required — never send X-API-KEY here)
  // ---------------------------------------------------------------------------

  /**
   * Pay a public invoice by reference. POST /v1/invoices/{reference}/pay.
   * Body is PayInvoiceRequest (no `amount` — the invoice fixes it).
   * @param {string} reference
   * @param {object} body  PayInvoiceRequest (see CONTRACT §4.1)
   * @returns {Promise<object>} PaymentResult
   */
  async payInvoice(reference, body) {
    const { data } = await this._request(
      'POST',
      `/v1/invoices/${encodeURIComponent(reference)}/pay`,
      { body: this._coerceAmount(body), auth: 'none', retry: false },
    );
    return data;
  }

  /**
   * Contribute to a public campaign. POST /v1/campaigns/{reference}/contribute.
   * @param {string} reference
   * @param {object} body  ContributeRequest (see CONTRACT §4.2)
   * @returns {Promise<object>} PaymentResult
   */
  async contributeCampaign(reference, body) {
    const { data } = await this._request(
      'POST',
      `/v1/campaigns/${encodeURIComponent(reference)}/contribute`,
      { body: this._coerceAmount(body), auth: 'none', retry: false },
    );
    return data;
  }

  /**
   * Pay a QR code by reference. POST /v1/merchant/qrcode/pay/{qrReference}.
   * `amount` is ignored server-side if the QR has a fixedAmount.
   * @param {string} reference  the QR reference
   * @param {object} body  QRPaymentRequest (see CONTRACT §4.3)
   * @returns {Promise<object>} PaymentResult
   */
  async payQr(reference, body) {
    const { data } = await this._request(
      'POST',
      `/v1/merchant/qrcode/pay/${encodeURIComponent(reference)}`,
      { body: this._coerceAmount(body), auth: 'none', retry: false },
    );
    return data;
  }

  /**
   * Public poll for a hosted-pay transaction (invoice/campaign/QR pages).
   * GET /v1/merchant/qrcode/status/{transactionId}. Returns a plain map; no
   * merchant/fee data. Poll ~every 5s.
   * @param {string} transactionId
   * @returns {Promise<object>}
   */
  async getQrStatus(transactionId) {
    const { data } = await this._request(
      'GET',
      `/v1/merchant/qrcode/status/${encodeURIComponent(transactionId)}`,
      { auth: 'none' },
    );
    return data;
  }

  // ---------------------------------------------------------------------------
  // Status normalization
  // ---------------------------------------------------------------------------

  /**
   * Collapse the two backend status vocabularies into one enum:
   *   SUCCESSFUL | SUCCESS  → 'SUCCEEDED'
   *   FAILED               → 'FAILED'
   *   PENDING              → 'PENDING'
   *   CANCEL               → 'CANCELLED'
   * Unknown values are upper-cased and returned as-is.
   * @param {string} status
   * @returns {string}
   */
  static normalizeStatus(status) {
    if (status === undefined || status === null || status === '') return 'UNKNOWN';
    switch (String(status).toUpperCase()) {
      case 'SUCCESSFUL':
      case 'SUCCESS':
        return 'SUCCEEDED';
      case 'FAILED':
        return 'FAILED';
      case 'PENDING':
        return 'PENDING';
      case 'CANCEL':
      case 'CANCELLED':
      case 'CANCELED':
        return 'CANCELLED';
      default:
        return String(status).toUpperCase();
    }
  }

  /** Instance convenience — delegates to the static implementation. */
  normalizeStatus(status) {
    return MarvinPayClient.normalizeStatus(status);
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /** Shallow-copy a PaymentRequest and coerce its amount to a whole number. */
  _preparePaymentRequest(req) {
    if (!req || typeof req !== 'object') {
      throw new MarvinPayError('paymentRequest must be an object', { code: 'INVALID_REQUEST' });
    }
    const out = { ...req };
    if (out.amount !== undefined && out.amount !== null) {
      out.amount = toWholeNumber(out.amount);
    }
    return out;
  }

  /** Shallow-copy a body and coerce `amount` (if present) to a whole number. */
  _coerceAmount(body) {
    if (!body || typeof body !== 'object') return body;
    if (body.amount === undefined || body.amount === null) return body;
    return { ...body, amount: toWholeNumber(body.amount) };
  }

  /** Build an absolute URL, appending a query string if provided. */
  _buildUrl(path, query) {
    let url = this.baseUrl + path;
    if (query) {
      const params = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null) params.append(k, String(v));
      }
      const qs = params.toString();
      if (qs) url += `?${qs}`;
    }
    return url;
  }

  /**
   * Perform a request with optional single retry on 429/5xx (GETs only unless
   * `retry` is forced). Returns { data, response }.
   */
  async _request(method, path, { body, query, idempotencyKey, auth = 'default', retry } = {}) {
    const url = this._buildUrl(path, query);

    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (auth === 'default') {
      // Per the contract: send X-API-KEY when set, and Authorization when set.
      if (this.apiKey) headers['X-API-KEY'] = this.apiKey;
      if (this.bearerToken) headers['Authorization'] = `Bearer ${this.bearerToken}`;
    }
    if (idempotencyKey) headers['X-Idempotency-Key'] = idempotencyKey;

    // Default: retry idempotent GETs once. Money-moving POSTs pass retry:false.
    const canRetry = retry === undefined ? method === 'GET' : retry;
    const maxAttempts = canRetry ? 2 : 1;

    let lastError;
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      let response;
      try {
        response = await this._fetch(method, url, headers, body);
      } catch (err) {
        // Transport-level failure (network/timeout). Retry once if allowed.
        lastError = err;
        if (attempt < maxAttempts) {
          await sleep(this._backoffMs(attempt, null));
          continue;
        }
        throw err;
      }

      const retryable = response.status === 429 || response.status >= 500;
      if (retryable && attempt < maxAttempts) {
        const retryAfter = response.headers.get('retry-after');
        // Drain the body so the socket can be reused before we retry.
        try {
          await response.text();
        } catch (_) {
          /* ignore */
        }
        await sleep(this._backoffMs(attempt, retryAfter));
        continue;
      }

      return this._handleResponse(response);
    }

    // Unreachable in practice, but keep the contract of always returning/throwing.
    throw lastError || new MarvinPayError('Request failed', { code: 'UNKNOWN' });
  }

  /** Issue a single fetch with an AbortController-based timeout. */
  async _fetch(method, url, headers, body) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);
    try {
      return await fetch(url, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
    } catch (err) {
      if (err && err.name === 'AbortError') {
        throw new MarvinPayError(`Request timed out after ${this.timeoutMs}ms: ${method} ${url}`, {
          code: 'TIMEOUT',
        });
      }
      throw new MarvinPayError(`Network error calling ${method} ${url}: ${err.message}`, {
        code: 'NETWORK',
      });
    } finally {
      clearTimeout(timer);
    }
  }

  /** Parse the response body and throw MarvinPayError on non-2xx. */
  async _handleResponse(response) {
    const text = await response.text();
    let data = null;
    if (text) {
      try {
        data = JSON.parse(text);
      } catch (_) {
        data = text; // non-JSON body — surface as raw text
      }
    }

    if (!response.ok) {
      const message =
        data && typeof data === 'object' && data.message
          ? data.message
          : response.statusText || `HTTP ${response.status}`;
      throw new MarvinPayError(message, { httpStatus: response.status, body: data });
    }

    return { data, response };
  }

  /**
   * Surface idempotency replay metadata as NON-enumerable properties on the
   * result so it never leaks into JSON.stringify / logging of the payload.
   *   result.idempotencyReplayed : boolean
   *   result.idempotencyKeyAuto  : string (only when the server auto-generated)
   */
  _attachIdempotency(result, response) {
    if (!result || typeof result !== 'object' || !response) return;
    const replayed = response.headers.get('x-idempotency-replay') === 'true';
    const autoKey = response.headers.get('x-idempotency-key-auto');
    Object.defineProperty(result, 'idempotencyReplayed', {
      value: replayed,
      enumerable: false,
      configurable: true,
    });
    if (autoKey) {
      Object.defineProperty(result, 'idempotencyKeyAuto', {
        value: autoKey,
        enumerable: false,
        configurable: true,
      });
    }
  }

  /** Short backoff. Respects `Retry-After` (seconds) when present. */
  _backoffMs(attempt, retryAfter) {
    if (retryAfter) {
      const secs = Number(retryAfter);
      if (Number.isFinite(secs) && secs >= 0) return Math.min(secs * 1000, 10000);
    }
    return 300 * attempt;
  }
}

// Optionally re-export the webhook helpers so consumers have a single import
// point (`require('@marvinpay/sdk')`). Kept in a try/catch so copying only
// marvin-pay.js (without webhook-verifier.js) still loads the client.
let _webhook = {};
try {
  // eslint-disable-next-line global-require
  _webhook = require('./webhook-verifier');
} catch (_) {
  _webhook = {};
}

module.exports = {
  MarvinPayClient,
  MarvinPayError,
  verifyWebhookSignature: _webhook.verifyWebhookSignature,
  parseWebhookEvent: _webhook.parseWebhookEvent,
};
module.exports.default = MarvinPayClient;
