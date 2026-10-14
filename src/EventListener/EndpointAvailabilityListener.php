<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventListener;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * When an endpoint is disabled (by an administrator or after "410 Gone"), its open deliveries
 * are suspended: they are not attempted, do not block the purge and are not dead letters.
 * Enabling the endpoint again requeues them (due immediately).
 *
 * postUpdate runs inside the flush transaction, after the endpoint row was updated.
 */
final readonly class EndpointAvailabilityListener
{
    /** @param class-string<WebhookDeliveryInterface> $deliveryClass */
    public function __construct(
        private ClockInterface $clock,
        private string $deliveryClass,
    ) {
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $endpoint = $args->getObject();
        if (!$endpoint instanceof WebhookEndpointInterface || null === $endpoint->getId()) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $change = $entityManager->getUnitOfWork()->getEntityChangeSet($endpoint)['enabled'] ?? null;
        if (!is_array($change) || $change[0] === $change[1]) {
            return;
        }

        $table = $entityManager->getClassMetadata($this->deliveryClass)->getTableName();
        $connection = $entityManager->getConnection();

        if (false === $change[1]) {
            $connection->executeStatement(
                sprintf('UPDATE %s SET status = ?, next_attempt_at = NULL, claimed_until = NULL, claim_token = NULL WHERE endpoint_id = ? AND status IN (?)', $table),
                [WebhookDeliveryInterface::STATUS_SUSPENDED, $endpoint->getId(), [WebhookDeliveryInterface::STATUS_PENDING, WebhookDeliveryInterface::STATUS_FAILED]],
                [Types::STRING, Types::INTEGER, ArrayParameterType::STRING],
            );

            return;
        }

        $connection->executeStatement(
            sprintf('UPDATE %s SET status = ?, next_attempt_at = ? WHERE endpoint_id = ? AND status = ?', $table),
            [WebhookDeliveryInterface::STATUS_PENDING, $this->clock->now()->setTimezone(new \DateTimeZone('UTC')), $endpoint->getId(), WebhookDeliveryInterface::STATUS_SUSPENDED],
            [Types::STRING, Types::DATETIME_IMMUTABLE, Types::INTEGER, Types::STRING],
        );
    }
}
