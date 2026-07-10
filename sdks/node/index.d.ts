// Type declarations for @marvinpay/sdk (marvin-pay.js + webhook-verifier.js).
// Field names mirror the wire contract exactly (snake_case for payment DTOs).
// See CONTRACT.md for the authoritative definitions.

// ---------------------------------------------------------------------------
// Enums / string unions
// ---------------------------------------------------------------------------

/** `fee_bearer` — who absorbs the fee. Omitted/null ⇒ MERCHANT. */
export type FeeBearer = 'MERCHANT' | 'CUSTOMER';

/** Fee direction for GET /v1/payment/fees. Merchants use COLLECT / PAYOUT. */
export type FeeDirection = 'COLLECT' | 'PAYOUT' | 'TOPUP' | 'WITHDRAWAL';

/** `transaction_status` on REST responses. */
export type TransactionStatus = 'SUCCESSFUL' | 'FAILED' | 'PENDING';

/** `status` on webhook payloads (note SUCCESS vs REST's SUCCESSFUL). */
export type WebhookStatus = 'SUCCESS' | 'FAILED' | 'PENDING' | 'CANCEL';

/** Webhook `event` discriminator. */
export type WebhookEventName =
  | 'transaction.success'
  | 'transaction.failed'
  | 'transaction.pending'
  | 'transaction.cancel';

/** Normalized status produced by `MarvinPayClient.normalizeStatus`. */
export type NormalizedStatus = 'SUCCEEDED' | 'FAILED' | 'PENDING' | 'CANCELLED' | 'UNKNOWN';

// ---------------------------------------------------------------------------
// Request / response shapes
// ---------------------------------------------------------------------------

/** Body for POST /v1/payment/collect and /v1/payment/payout. */
export interface PaymentRequest {
  /** ISO-3166 alpha-2, e.g. "CM". Always travels with `currency`. */
  country_code: string;
  /** ISO-4217, "XAF" | "XOF". Must match the country. */
  currency: string;
  /** Whole number, 100–500000. Serialized without a fractional part. */
  amount: number;
  /** Payer's (collect) or recipient's (payout) mobile-money number. */
  mobile_number: string;
  /** Provider name from GET /v1/payment/payment-methods/{countryCode}. */
  payment_method: string;
  /** YOUR unique reference for this transaction. */
  transaction_id: string;
  /** Payer name (collect) / recipient name (payout). */
  beneficiary_name?: string;
  description?: string;
  /** If set, a receipt email is sent. */
  customer_email?: string;
  /** MERCHANT (default) | CUSTOMER. */
  fee_bearer?: FeeBearer;
  /** Alternative to the X-Idempotency-Key header. */
  idempotency_key?: string;
}

/** Response from collect / payout / hosted-pay flows. */
export interface PaymentResult {
  /** Echoes your reference. */
  transaction_id: string;
  /** HTTP-style code, e.g. 200 / 202. */
  status: number;
  message: string;
  /** Gateway/operator reference. */
  partner_transaction_id?: string;
  transaction_status: TransactionStatus;
  /** Non-enumerable: true if the server replayed a prior idempotent response. */
  readonly idempotencyReplayed?: boolean;
  /** Non-enumerable: present only when the server auto-generated the key. */
  readonly idempotencyKeyAuto?: string;
}

/** Response from GET /v1/payment/status/{transactionId}. */
export interface TransactionStatusResponse {
  transaction_id: string;
  /** HTTP-style code. */
  status: number;
  message: string;
  currency: string;
  timestamp: string | number;
  transaction_status: TransactionStatus;
}

/**
 * Response from GET /v1/payment/fees. Known fields are typed; the live response
 * is authoritative for any extras (hence the index signature).
 */
export interface FeeEstimateResponse {
  baseAmount?: number;
  feeBearer?: FeeBearer;
  direction?: FeeDirection;
  amountChargedToCustomer?: number;
  amountCreditedToMerchant?: number;
  amountDebitedFromMerchant?: number;
  amountReceivedByRecipient?: number;
  [key: string]: unknown;
}

/** Body for POST /v1/invoices/{reference}/pay (no `amount`; invoice fixes it). */
export interface PayInvoiceRequest {
  country_code: string;
  currency: string;
  mobile_number: string;
  payment_method: string;
  /** Required — payer name. */
  beneficiary_name: string;
  customer_email?: string;
}

/** Body for POST /v1/campaigns/{reference}/contribute. */
export interface ContributeRequest {
  country_code: string;
  currency: string;
  /** Whole number, min 100. */
  amount: number;
  mobile_number: string;
  payment_method: string;
  beneficiary_name: string;
  customer_email?: string;
}

/** Body for POST /v1/merchant/qrcode/pay/{qrReference}. */
export interface QRPaymentRequest {
  country_code: string;
  currency: string;
  /** Whole number. Ignored server-side if the QR has a fixedAmount. */
  amount?: number;
  mobile_number: string;
  payment_method: string;
  /** Required. */
  beneficiary_name: string;
  customer_email?: string;
}

/** Public poll response from GET /v1/merchant/qrcode/status/{transactionId}. */
export interface QrStatusResponse {
  transactionId: string;
  status: string;
  amount: number;
  currency: string;
  paymentMethod: string;
  mobileNumber: string;
  timestamp: string | number;
  [key: string]: unknown;
}

/** Outbound webhook payload (see CONTRACT §8.4). */
export interface WebhookEvent {
  event: WebhookEventName;
  transactionId: string;
  operatorTransactionId?: string;
  status: WebhookStatus;
  amount: number;
  currency: string;
  baseAmount?: number;
  feeBearer?: FeeBearer;
  /** Present on credit/collect payloads. */
  amountChargedToCustomer?: number;
  /** Present on credit/collect payloads. */
  amountCreditedToMerchant?: number;
  /** Present on debit/payout payloads. */
  amountDebitedFromMerchant?: number;
  /** Present on debit/payout payloads. */
  amountReceivedByRecipient?: number;
  timestamp: number;
  paymentMethod?: string;
  country?: string;
  description?: string;
  [key: string]: unknown;
}

// ---------------------------------------------------------------------------
// Client
// ---------------------------------------------------------------------------

export interface MarvinPayClientOptions {
  /** Merchant API key, sent as `X-API-KEY`. */
  apiKey?: string;
  /** Defaults to `https://api.marvincorporate.co/api`. Must include `/api`. */
  baseUrl?: string;
  /** Optional portal JWT, sent as `Authorization: Bearer`. */
  bearerToken?: string;
  /** Per-request timeout in ms (default 30000). */
  timeoutMs?: number;
}

export interface IdempotencyOptions {
  /** Defaults to `paymentRequest.transaction_id` when omitted. */
  idempotencyKey?: string;
}

export interface GetFeesParams {
  currency: string;
  amount?: number;
  direction: FeeDirection;
  feeBearer?: FeeBearer;
}

export interface WaitForCompletionOptions {
  /** First poll delay (default 5000). */
  startDelayMs?: number;
  /** Backoff cap (default 60000). */
  maxDelayMs?: number;
  /** Give-up window (default 600000). */
  timeoutMs?: number;
}

export declare class MarvinPayError extends Error {
  readonly name: 'MarvinPayError';
  /** HTTP status, or 0 for transport-level (network/timeout) errors. */
  readonly httpStatus: number;
  /** Parsed response body when available. */
  readonly body: unknown;
  /** Machine-readable code, e.g. 'TIMEOUT' | 'NETWORK' | 'INVALID_AMOUNT'. */
  readonly code?: string;
  constructor(message: string, opts?: { httpStatus?: number; body?: unknown; code?: string });
}

export declare class MarvinPayClient {
  apiKey?: string;
  bearerToken?: string;
  baseUrl: string;
  timeoutMs: number;

  constructor(options?: MarvinPayClientOptions);

  // Core payment API (X-API-KEY)
  collect(paymentRequest: PaymentRequest, opts?: IdempotencyOptions): Promise<PaymentResult>;
  payout(paymentRequest: PaymentRequest, opts?: IdempotencyOptions): Promise<PaymentResult>;
  getStatus(transactionId: string): Promise<TransactionStatusResponse>;
  getFees(params: GetFeesParams): Promise<FeeEstimateResponse>;
  getPaymentMethods(countryCode: string): Promise<string[]>;
  waitForCompletion(
    transactionId: string,
    opts?: WaitForCompletionOptions,
  ): Promise<TransactionStatusResponse>;

  // Public hosted-pay flows (no auth)
  payInvoice(reference: string, body: PayInvoiceRequest): Promise<PaymentResult>;
  contributeCampaign(reference: string, body: ContributeRequest): Promise<PaymentResult>;
  payQr(reference: string, body: QRPaymentRequest): Promise<PaymentResult>;
  getQrStatus(transactionId: string): Promise<QrStatusResponse>;

  // Status normalization
  static normalizeStatus(status: string | null | undefined): NormalizedStatus;
  normalizeStatus(status: string | null | undefined): NormalizedStatus;
}

// ---------------------------------------------------------------------------
// Webhook helpers (also re-exported from the main entry point)
// ---------------------------------------------------------------------------

/**
 * Verify an HMAC-SHA256 webhook signature (constant-time). INERT today: webhooks
 * are unsigned in production, so this returns false until backend signing ships.
 * Always confirm via `getStatus` regardless. See CONTRACT §8.5.
 */
export declare function verifyWebhookSignature(
  rawBody: string | Buffer,
  signatureHeader: string | null | undefined,
  secret: string,
): boolean;

/** Parse a raw webhook body into a WebhookEvent (JSON.parse with Buffer support). */
export declare function parseWebhookEvent(rawBody: string | Buffer): WebhookEvent;

export default MarvinPayClient;
