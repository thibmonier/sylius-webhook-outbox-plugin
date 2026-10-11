<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/**
 * @extends RepositoryInterface<OutboxEventInterface>
 */
interface OutboxEventRepositoryInterface extends RepositoryInterface
{
    /** @return list<OutboxEventInterface> */
    public function findByCustomerId(int $customerId): array;

    /**
     * Deletes the events that occurred before the date and have no delivery left to do
     * (deliveries are deleted with them). At most $limit events per call.
     */
    public function purgeBefore(\DateTimeImmutable $before, int $limit): int;

    public function countPurgeableBefore(\DateTimeImmutable $before): int;
}
