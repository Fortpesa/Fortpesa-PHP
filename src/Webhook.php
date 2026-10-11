<?php
declare(strict_types=1);

namespace Fortpesa;

use Fortpesa\Exception\SignatureVerificationException;
use Fortpesa\Model\WebhookEvent;

/**
 * Verifies Fortpesa webhook deliveries.
 *
 * Signature = "sha256=" . hex(HMAC_SHA256("{timestamp}.{raw_body}", whsec_...)), checked against the
 * RAW request body, in constant time, with a 5 minute replay window.
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    public static function computeSignature(string $timestamp, string $rawBody, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * @param string|null $signatureHeader value of X-Fortpesa-Signature
     * @param string|null $timestampHeader value of X-Fortpesa-Timestamp
     * @param int         $tolerance       max clock difference in seconds
     * @param int|null    $now             override the current time (tests)
     *
     * @throws SignatureVerificationException
     */
    public static function constructEvent(
        string $rawBody,
        ?string $signatureHeader,
        ?string $timestampHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null
    ): WebhookEvent {
        if ($secret === '') {
            throw new SignatureVerificationException('Webhook secret is empty.');
        }
        if ($signatureHeader === null || $signatureHeader === '' || $timestampHeader === null || !ctype_digit($timestampHeader)) {
            throw new SignatureVerificationException('Missing or malformed X-Fortpesa-Signature / X-Fortpesa-Timestamp header.');
        }

        $expected = self::computeSignature($timestampHeader, $rawBody, $secret);
        if (!hash_equals($expected, $signatureHeader)) {
            throw new SignatureVerificationException('Webhook signature does not match.');
        }

        if (abs(($now ?? time()) - (int) $timestampHeader) > $tolerance) {
            throw new SignatureVerificationException('Webhook timestamp is outside the allowed tolerance (possible replay).');
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['event']) || !is_string($payload['event'])) {
            throw new SignatureVerificationException('Webhook body is not a valid event payload.');
        }

        return new WebhookEvent(
            $payload['event'],
            isset($payload['created_at']) ? (string) $payload['created_at'] : null,
            is_array($payload['data'] ?? null) ? $payload['data'] : []
        );
    }

    /**
     * Convenience for plain PHP endpoints: reads php://input and the X-Fortpesa-* headers from $_SERVER.
     *
     * @throws SignatureVerificationException
     */
    public static function constructEventFromGlobals(string $secret, int $tolerance = self::DEFAULT_TOLERANCE): WebhookEvent
    {
        $raw = (string) file_get_contents('php://input');
        return self::constructEvent(
            $raw,
            isset($_SERVER['HTTP_X_FORTPESA_SIGNATURE']) ? (string) $_SERVER['HTTP_X_FORTPESA_SIGNATURE'] : null,
            isset($_SERVER['HTTP_X_FORTPESA_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_FORTPESA_TIMESTAMP'] : null,
            $secret,
            $tolerance
        );
    }
}
