<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Entity;

use Sylius\Resource\Model\ResourceInterface;

/**
 * A business event written in the same database transaction as the change that produced it.
 * Its UUIDv7 id is the "webhook-id" of every delivery (retries and replays included), so that
 * receivers can deduplicate.
 */
interface OutboxEventInterface extends ResourceInterface
{
    public function getId(): string;

    public function getType(): string;

    public function getVersion(): int;

    /** UTC. */
    public function getOccurredAt(): \DateTimeImmutable;

    public function getChannelCode(): ?string;

    /** Short resource name of the subject: order, customer, product_variant, webhook_endpoint... */
    public function getResourceType(): ?string;

    public function getResourceId(): ?string;

    public function getCustomerId(): ?int;

    /**
     * The "data" member of the envelope.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;

    /** @param array<string, mixed> $payload */
    public function replacePayload(array $payload): void;

    public function getAnonymizedAt(): ?\DateTimeImmutable;

    public function markAnonymized(\DateTimeImmutable $at): void;

    public function isAnonymized(): bool;

    /** Resolves the references unknown when the event was recorded (ids of entities inserted in the same flush). */
    public function resolveReferences(): bool;
}
