<?php

declare(strict_types=1);

namespace MarvinPay;

/**
 * Thrown for any non-2xx HTTP response, transport/cURL failure, or client-side
 * validation error raised by {@see MarvinPayClient}.
 *
 * Carries the HTTP status (when there was a response), the human-readable
 * message (extracted from the response body's `message` field when present),
 * and the raw/decoded response body for inspection.
 */
class MarvinPayException extends \RuntimeException
{
    private ?int $httpStatus;

    /** @var mixed decoded array/object when JSON, raw string otherwise, or null */
    private $body;

    /**
     * @param mixed $body decoded body (array), raw string, or null
     */
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        $body = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->body = $body;
    }

    /** HTTP status code, or null for transport/client-side errors. */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * The response body as decoded by the client (array for JSON, string for
     * non-JSON, or null when there was no body).
     *
     * @return mixed
     */
    public function getBody()
    {
        return $this->body;
    }
}
