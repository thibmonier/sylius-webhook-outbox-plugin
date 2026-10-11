<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

/**
 * One event to one endpoint. Retries update the same row; a replay creates a new row with the
 * same event (hence the same webhook-id).
 */
interface WebhookDeliveryInterface extends ResourceInterface
{
    /** Waiting for its first attempt. */
    public const STATUS_PENDING = 'pending';

    /** The last attempt failed, another one is scheduled at nextAttemptAt. */
    public const STATUS_FAILED = 'failed';

    public const STATUS_DELIVERED = 'delivered';

    /** No attempt left, or the endpoint answered 410 Gone (dead letter). */
    public const STATUS_DEAD = 'dead';

    /** The endpoint is disabled: not attempted until it is enabled again. */
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_FAILED, self::STATUS_DELIVERED, self::STATUS_DEAD, self::STATUS_SUSPENDED];

    /** Number of attempts kept in the history. */
    public const HISTORY_SIZE = 10;

    public function getId(): ?int;

    public function getEvent(): OutboxEventInterface;

    public function getEndpoint(): WebhookEndpointInterface;

    public function getStatus(): string;

    public function isDue(\DateTimeImmutable $now): bool;

    public function getAttempts(): int;

    /** UTC. */
    public function getNextAttemptAt(): ?\DateTimeImmutable;

    public function getLastAttemptAt(): ?\DateTimeImmutable;

    public function getDeliveredAt(): ?\DateTimeImmutable;

    public function getCreatedAt(): \DateTimeImmutable;

    public function getLastResponseStatus(): ?int;

    public function getLastResponseBody(): ?string;

    public function getLastError(): ?string;

    public function getReplayOf(): ?int;

    /**
     * Last attempts, newest first: {at, duration_ms, status_code, error, response_body}.
     *
     * @return list<array{at: string, duration_ms: int, status_code: int|null, error: string|null, response_body: string|null}>
     */
    public function getAttemptHistory(): array;

    public function recordAttempt(DeliveryAttempt $attempt): void;

    public function markDelivered(\DateTimeImmutable $at): void;

    public function scheduleRetry(\DateTimeImmutable $nextAttemptAt): void;

    public function markDead(): void;

    /** "Retry now": a failed or pending delivery is due immediately. */
    public function retryNow(\DateTimeImmutable $now): bool;

    public function canBeRetriedNow(): bool;

    public function canBeReplayed(): bool;

    /** Removes the response excerpts (personal data erasure). */
    public function eraseResponses(): void;
}
