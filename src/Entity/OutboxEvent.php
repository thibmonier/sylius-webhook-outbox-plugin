<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

use Symfony\Component\Uid\Uuid;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\DeferredPayload;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

class OutboxEvent implements OutboxEventInterface
{
    protected string $id;

    protected string $type;

    protected int $version;

    protected \DateTimeImmutable $occurredAt;

    protected ?string $channelCode;

    protected ?string $resourceType;

    protected ?string $resourceId = null;

    protected ?int $customerId = null;

    /**
     * Doctrine "json" column. A DeferredPayload until the row is inserted, an array afterwards.
     *
     * @var array<string, mixed>|DeferredPayload
     */
    protected array|DeferredPayload $payload;

    protected ?\DateTimeImmutable $anonymizedAt = null;

    /** Not mapped: references resolved after the insert (see resolveReferences()). */
    private int|string|LazyValue|null $pendingResourceId = null;

    private int|string|LazyValue|null $pendingCustomerId = null;

    /**
     * @param array<string, mixed>|DeferredPayload $payload
     */
    public function __construct(
        string $type,
        int $version,
        \DateTimeImmutable $occurredAt,
        array|DeferredPayload $payload,
        ?string $channelCode = null,
        ?string $resourceType = null,
        int|string|LazyValue|null $resourceId = null,
        int|string|LazyValue|null $customerId = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
        $this->type = $type;
        $this->version = $version;
        $this->occurredAt = $occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $this->payload = $payload;
        $this->channelCode = $channelCode;
        $this->resourceType = $resourceType;
        $this->pendingResourceId = $resourceId;
        $this->pendingCustomerId = $customerId;
        $this->resolveReferences();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        // Stored in UTC without time zone: read it back as UTC whatever the PHP default.
        return new \DateTimeImmutable($this->occurredAt->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public function getChannelCode(): ?string
    {
        return $this->channelCode;
    }

    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }

    public function getResourceId(): ?string
    {
        return $this->resourceId;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getPayload(): array
    {
        return $this->payload instanceof DeferredPayload ? $this->payload->toArray() : $this->payload;
    }

    public function replacePayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getAnonymizedAt(): ?\DateTimeImmutable
    {
        return $this->anonymizedAt;
    }

    public function markAnonymized(\DateTimeImmutable $at): void
    {
        $this->anonymizedAt = $at;
    }

    public function isAnonymized(): bool
    {
        return null !== $this->anonymizedAt;
    }

    public function resolveReferences(): bool
    {
        $changed = false;

        $resourceId = $this->pendingResourceId instanceof LazyValue ? $this->pendingResourceId->resolve() : $this->pendingResourceId;
        if ((is_int($resourceId) || is_string($resourceId)) && (string) $resourceId !== $this->resourceId) {
            $this->resourceId = (string) $resourceId;
            $this->pendingResourceId = null;
            $changed = true;
        }

        $customerId = $this->pendingCustomerId instanceof LazyValue ? $this->pendingCustomerId->resolve() : $this->pendingCustomerId;
        if (is_numeric($customerId) && (int) $customerId !== $this->customerId) {
            $this->customerId = (int) $customerId;
            $this->pendingCustomerId = null;
            $changed = true;
        }

        return $changed;
    }
}
