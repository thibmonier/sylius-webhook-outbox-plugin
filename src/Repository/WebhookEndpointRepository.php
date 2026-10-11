<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

class WebhookEndpointRepository extends EntityRepository implements WebhookEndpointRepositoryInterface
{
    public function findSubscribed(string $eventType, ?string $channelCode): array
    {
        /** @var list<WebhookEndpointInterface> $endpoints */
        $endpoints = $this->createQueryBuilder('o')
            ->andWhere('o.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        // Subscriptions are a JSON list: filtered here, an installation has a handful of endpoints.
        return array_values(array_filter(
            $endpoints,
            static fn (WebhookEndpointInterface $endpoint): bool => $endpoint->isSubscribedTo($eventType) && $endpoint->acceptsChannel($channelCode),
        ));
    }
}
