<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

/**
 * Outcome of one HTTP attempt. The response body is already truncated and the error message
 * free of endpoint credentials (see HttpWebhookSender).
 */
final class DeliveryAttempt
{
    public function __construct(
        public readonly \DateTimeImmutable $at,
        public readonly int $durationMs,
        public readonly ?int $statusCode,
        public readonly ?string $responseBody = null,
        public readonly ?string $error = null,
        public readonly ?int $retryAfterSeconds = null,
        /** No retry: the request could not even be built (invalid URL, missing secret). */
        public readonly bool $permanentFailure = false,
    ) {
    }

    public function isSuccessful(): bool
    {
        return null !== $this->statusCode && $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** @return array{at: string, duration_ms: int, status_code: int|null, error: string|null, response_body: string|null} */
    public function toArray(): array
    {
        return [
            'at' => $this->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $this->durationMs,
            'status_code' => $this->statusCode,
            'error' => $this->error,
            'response_body' => $this->responseBody,
        ];
    }
}
