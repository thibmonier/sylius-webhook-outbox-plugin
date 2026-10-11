<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * Claims the next due delivery with SELECT ... FOR UPDATE SKIP LOCKED (MySQL 8, MariaDB 10.6+,
 * PostgreSQL; DBAL 3.9+ and 4) and a lease: claimed_until / claim_token. A worker that crashes
 * leaves a lease that expires, after which the delivery is claimed again (at-least-once).
 */
final class DeliveryClaimer
{
    private ?bool $skipLockedSupported = null;

    /**
     * @param class-string<WebhookDeliveryInterface> $deliveryClass
     * @param class-string<WebhookEndpointInterface> $endpointClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly string $deliveryClass,
        private readonly string $endpointClass,
        private readonly int $leaseSeconds,
    ) {
    }

    public function claimNext(): ?DeliveryClaim
    {
        $connection = $this->entityManager->getConnection();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $deliveries = $this->entityManager->getClassMetadata($this->deliveryClass);
        $endpoints = $this->entityManager->getClassMetadata($this->endpointClass);

        // Separate query: locking a join would lock the endpoint rows too.
        $endpointIds = $connection->createQueryBuilder()
            ->select('id')
            ->from($endpoints->getTableName())
            ->where('enabled = :enabled')
            ->setParameter('enabled', true, ParameterType::BOOLEAN)
            ->executeQuery()
            ->fetchFirstColumn()
        ;
        if ([] === $endpointIds) {
            return null;
        }

        return $connection->transactional(function (Connection $connection) use ($now, $deliveries, $endpointIds): ?DeliveryClaim {
            $query = $connection->createQueryBuilder()
                ->select('id')
                ->from($deliveries->getTableName())
                ->where('status IN (:statuses)')
                ->andWhere('next_attempt_at <= :now')
                ->andWhere('(claimed_until IS NULL OR claimed_until < :now)')
                ->andWhere('endpoint_id IN (:endpoints)')
                ->orderBy('next_attempt_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->setMaxResults(1)
                ->setParameter('statuses', [WebhookDeliveryInterface::STATUS_PENDING, WebhookDeliveryInterface::STATUS_FAILED], ArrayParameterType::STRING)
                ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                ->setParameter('endpoints', array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $endpointIds), ArrayParameterType::INTEGER)
            ;
            $this->lock($query);

            $id = $query->executeQuery()->fetchOne();
            if (!is_numeric($id)) {
                return null;
            }

            $token = bin2hex(random_bytes(16));
            $connection->update(
                $deliveries->getTableName(),
                ['claimed_until' => $now->modify(sprintf('+%d seconds', $this->leaseSeconds)), 'claim_token' => $token],
                ['id' => $id],
                ['claimed_until' => Types::DATETIME_IMMUTABLE],
            );

            return new DeliveryClaim((int) $id, $token);
        });
    }

    /**
     * True when the claim still belongs to the caller; locks the row until the end of the
     * current transaction.
     */
    public function holds(DeliveryClaim $claim): bool
    {
        $deliveries = $this->entityManager->getClassMetadata($this->deliveryClass);
        $query = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('claim_token')
            ->from($deliveries->getTableName())
            ->where('id = :id')
            ->setParameter('id', $claim->deliveryId)
            ->forUpdate()
        ;

        return $claim->token === $query->executeQuery()->fetchOne();
    }

    public function isSkipLockedSupported(): bool
    {
        if (null === $this->skipLockedSupported) {
            $query = $this->entityManager->getConnection()->createQueryBuilder()->select('1')->from('dual_check');

            try {
                $query->forUpdate(ConflictResolutionMode::SKIP_LOCKED)->getSQL();
                $this->skipLockedSupported = true;
            } catch (\Doctrine\DBAL\Exception) {
                // Old platform (e.g. serverVersion pinned to MySQL 5.7): plain FOR UPDATE, one worker only.
                $this->skipLockedSupported = false;
            }
        }

        return $this->skipLockedSupported;
    }

    private function lock(QueryBuilder $query): void
    {
        if ($this->isSkipLockedSupported()) {
            $query->forUpdate(ConflictResolutionMode::SKIP_LOCKED);
        } else {
            $query->forUpdate();
        }
    }
}
