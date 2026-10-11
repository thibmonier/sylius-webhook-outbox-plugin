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
}
