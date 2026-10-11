<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Signature;

/**
 * Standard Webhooks v1 (symmetric) signatures:
 * base64(HMAC-SHA256(secret bytes, "{webhook-id}.{webhook-timestamp}.{body}")), sent as
 * "v1,<signature>" in the webhook-signature header.
 *
 * @see https://github.com/standard-webhooks/standard-webhooks/blob/main/spec/standard-webhooks.md
 */
final class WebhookSigner
{
    public const SECRET_PREFIX = 'whsec_';

    /** Tolerance of verify(), in seconds (replay protection on the receiver side). */
    public const DEFAULT_TOLERANCE = 300;

    public function sign(string $webhookId, int $timestamp, string $body, #[\SensitiveParameter] string $secret): string
    {
        $signature = hash_hmac('sha256', sprintf('%s.%d.%s', $webhookId, $timestamp, $body), self::secretBytes($secret), true);

        return 'v1,' . base64_encode($signature);
    }

    /**
     * @return array{webhook-id: string, webhook-timestamp: string, webhook-signature: string}
     */
    public function headers(string $webhookId, int $timestamp, string $body, #[\SensitiveParameter] string $secret): array
    {
        return [
            'webhook-id' => $webhookId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => $this->sign($webhookId, $timestamp, $body, $secret),
        ];
    }

    /**
     * Receiver-side check, also used by the tests: one of the space-separated "v1,..." signatures
     * must match and the timestamp must be within the tolerance.
     */
    public function verify(
        string $webhookId,
        string $timestamp,
        string $body,
        string $signatureHeader,
        #[\SensitiveParameter] string $secret,
        int $now,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): bool {
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = $this->sign($webhookId, (int) $timestamp, $body, $secret);
        foreach (explode(' ', $signatureHeader) as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /** Decoded key: the base64 part of "whsec_<base64>". */
    public static function secretBytes(#[\SensitiveParameter] string $secret): string
    {
        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, strlen(self::SECRET_PREFIX)) : $secret;
        $bytes = base64_decode($encoded, true);
        if (false === $bytes || '' === $bytes) {
            // Never include the secret in the message.
            throw new \InvalidArgumentException('The webhook secret is not a valid "whsec_" base64 secret.');
        }

        return $bytes;
    }
}
