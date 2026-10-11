<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

class WebhookDelivery implements WebhookDeliveryInterface
{
    protected ?int $id = null;

    protected string $status = self::STATUS_PENDING;

    protected int $attempts = 0;

    protected ?\DateTimeImmutable $nextAttemptAt;

    protected ?\DateTimeImmutable $lastAttemptAt = null;

    protected ?\DateTimeImmutable $deliveredAt = null;

    protected \DateTimeImmutable $createdAt;

    protected ?int $lastResponseStatus = null;

    protected ?string $lastResponseBody = null;

    protected ?string $lastError = null;

    /** @var list<array{at: string, duration_ms: int, status_code: int|null, error: string|null, response_body: string|null}> */
    protected array $attemptHistory = [];

    /** Lease of the worker that claimed the delivery (written with DBAL by DeliveryClaimer). */
    protected ?\DateTimeImmutable $claimedUntil = null;

    protected ?string $claimToken = null;

    public function __construct(
        protected OutboxEventInterface $event,
        protected WebhookEndpointInterface $endpoint,
        \DateTimeImmutable $createdAt,
        protected ?int $replayOf = null,
        ?\DateTimeImmutable $nextAttemptAt = null,
    ) {
        $this->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'));
        $this->nextAttemptAt = ($nextAttemptAt ?? $createdAt)->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): OutboxEventInterface
    {
        return $this->event;
    }

    public function getEndpoint(): WebhookEndpointInterface
    {
        return $this->endpoint;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        $next = $this->getNextAttemptAt();

        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true) && null !== $next && $next <= $now;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getNextAttemptAt(): ?\DateTimeImmutable
    {
        return self::utc($this->nextAttemptAt);
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return self::utc($this->lastAttemptAt);
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return self::utc($this->deliveredAt);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return self::utc($this->createdAt) ?? $this->createdAt;
    }

    public function getLastResponseStatus(): ?int
    {
        return $this->lastResponseStatus;
    }

    public function getLastResponseBody(): ?string
    {
        return $this->lastResponseBody;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getReplayOf(): ?int
    {
        return $this->replayOf;
    }

    public function getAttemptHistory(): array
    {
        return $this->attemptHistory;
    }

    public function recordAttempt(DeliveryAttempt $attempt): void
    {
        ++$this->attempts;
        $this->lastAttemptAt = $attempt->at->setTimezone(new \DateTimeZone('UTC'));
        $this->lastResponseStatus = $attempt->statusCode;
        $this->lastResponseBody = $attempt->responseBody;
        $this->lastError = null === $attempt->error ? null : mb_substr($attempt->error, 0, 1000);
        $this->attemptHistory = array_slice([$attempt->toArray(), ...$this->attemptHistory], 0, self::HISTORY_SIZE);
        $this->claimedUntil = null;
        $this->claimToken = null;
    }

    public function markDelivered(\DateTimeImmutable $at): void
    {
        $this->status = self::STATUS_DELIVERED;
        $this->deliveredAt = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->nextAttemptAt = null;
    }

    public function scheduleRetry(\DateTimeImmutable $nextAttemptAt): void
    {
        $this->status = self::STATUS_FAILED;
        $this->nextAttemptAt = $nextAttemptAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function markDead(): void
    {
        $this->status = self::STATUS_DEAD;
        $this->nextAttemptAt = null;
        $this->claimedUntil = null;
        $this->claimToken = null;
    }

    public function retryNow(\DateTimeImmutable $now): bool
    {
        if (!$this->canBeRetriedNow()) {
            return false;
        }
        $this->nextAttemptAt = $now->setTimezone(new \DateTimeZone('UTC'));

        return true;
    }

    public function canBeRetriedNow(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_FAILED], true);
    }

    public function canBeReplayed(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_DEAD], true) && !$this->event->isAnonymized();
    }

    public function eraseResponses(): void
    {
        $this->lastResponseBody = null;
        $this->lastError = null;
        $this->attemptHistory = array_map(
            static fn (array $attempt): array => ['response_body' => null, 'error' => null] + $attempt,
            $this->attemptHistory,
        );
    }

    private static function utc(?\DateTimeImmutable $date): ?\DateTimeImmutable
    {
        // Stored in UTC without time zone: read it back as UTC whatever the PHP default.
        return null === $date ? null : new \DateTimeImmutable($date->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }
}
