<?php

namespace App\Pixelforge;

/**
 * Verifies the Pixelforge-Signature header: `t=<unix timestamp>,v1=<hex hmac>`,
 * where the HMAC-SHA256 covers "<timestamp>.<raw request body>" and is keyed
 * with the signing secret of the API key that created the generation.
 */
final class WebhookSignature
{
    /** Reject timestamps older (or newer) than this, to stop replays. */
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $payload, ?string $header, string $secret, int $now): bool
    {
        // An unset secret would let anyone sign a request with an empty key.
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }

        $parts = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if (! ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return hash_equals($expected, $signature)
            && abs($now - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }

    /** Build a valid header. Used by tests and by local webhook simulation. */
    public static function sign(string $payload, string $secret, int $timestamp): string
    {
        return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
    }
}
