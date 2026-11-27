<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

/**
 * @extends RepositoryInterface<WebhookDeliveryInterface>
 */
interface WebhookDeliveryRepositoryInterface extends RepositoryInterface
{
    /** @return list<WebhookDeliveryInterface> */
    public function findByEvent(OutboxEventInterface $event): array;

    /** @return array<string, int> number of deliveries by status */
    public function countByStatus(): array;

    /**
     * The date at which the oldest pending delivery that is due became due (served by the "status, next_attempt_at, id" index), or null when none is.
     */
    public function oldestPendingDueAt(\DateTimeImmutable $now): ?\DateTimeImmutable;

    /**
     * Number of dead deliveries, counted up to $limit: never more than $limit, so that the cost stays bounded.
     */
    public function countDeadUpTo(int $limit): int;
}
