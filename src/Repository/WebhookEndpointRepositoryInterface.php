<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * @extends RepositoryInterface<WebhookEndpointInterface>
 */
interface WebhookEndpointRepositoryInterface extends RepositoryInterface
{
    /**
     * Enabled endpoints subscribed to the type and accepting the channel.
     *
     * @return list<WebhookEndpointInterface>
     */
    public function findSubscribed(string $eventType, ?string $channelCode): array;
}
