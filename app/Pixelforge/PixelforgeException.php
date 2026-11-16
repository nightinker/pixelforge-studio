<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

class PixelforgeException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error') ?? [];
        $retryAfter = $response->header('Retry-After');

        return new self(
            message: $error['message'] ?? "Pixelforge returned HTTP {$response->status()}.",
            errorCode: $error['code'] ?? 'unknown_error',
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            param: $error['param'] ?? null,
        );
    }

    public static function unreachable(ConnectionException $e): self
    {
        return new self($e->getMessage(), 'unreachable');
    }

    /**
     * Whether the same request may succeed if sent again shortly.
     *
     * Rejected requests create and bill nothing, so retrying is safe. A daily
     * quota is deliberately not retryable: it will not clear until the next UTC day.
     */
    public function retryable(): bool
    {
        return in_array($this->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded', 'overloaded', 'unreachable'], true)
            || $this->status >= 500;
    }

    /** Text that is safe and useful to show the user. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Your prompt was rejected by the image provider\'s content policy. Please change it and try again.',
            'validation_failed' => $this->getMessage(),
            'overloaded', 'unreachable' => 'The image provider is busy right now. Nothing was charged. Please try again in a moment.',
            'rate_limit_exceeded', 'concurrency_limit_exceeded' => 'Too many images are being generated at once. Nothing was charged. Please try again shortly.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again tomorrow.',
            default => 'Something went wrong while contacting the image provider. Nothing was charged.',
        };
    }
}
