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
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * Claims the next due delivery and gives it a lease: claimed_until / claim_token. A worker
 * that crashes leaves a lease that expires, after which the delivery is claimed again
 * (at-least-once).
 *
 * Two steps, so that the claim never locks more than one row (InnoDB locks every row a locking
 * read scans, gaps included, which would block concurrent workers and the checkout inserts):
 *  1. a plain read of a few candidates, per status, along the (status, next_attempt_at, id)
 *     index;
 *  2. SELECT ... WHERE id = ? (primary key) ... FOR UPDATE SKIP LOCKED on each candidate until
 *     one is still due and free. Without SKIP LOCKED (server_version pinned to an old MySQL),
 *     a plain FOR UPDATE on one primary key.
 */
final class DeliveryClaimer
{
    private const CANDIDATES = 10;

    private const OPEN_STATUSES = [WebhookDeliveryInterface::STATUS_PENDING, WebhookDeliveryInterface::STATUS_FAILED];

    private ?bool $skipLockedSupported = null;

    /**
     * @param class-string<WebhookDeliveryInterface> $deliveryClass
     * @param class-string<WebhookEndpointInterface> $endpointClass
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly string $deliveryClass,
        private readonly string $endpointClass,
        private readonly int $leaseSeconds,
    ) {
    }

    public function claimNext(): ?DeliveryClaim
    {
        return $this->claimOn($this->entityManager()->getConnection());
    }

    /** Claims with the given connection (a second worker in the tests). */
    public function claimOn(Connection $connection): ?DeliveryClaim
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $table = $this->deliveryTable();

        $this->reconcileEndpointStates($connection);

        // Separate query: locking a join would lock the endpoint rows too.
        $endpointIds = array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $connection->createQueryBuilder()
                ->select('id')
                ->from($this->entityManager()->getClassMetadata($this->endpointClass)->getTableName())
                ->where('enabled = :enabled')
                ->setParameter('enabled', true, ParameterType::BOOLEAN)
                ->executeQuery()
                ->fetchFirstColumn(),
        );
        if ([] === $endpointIds) {
            return null;
        }

        foreach ($this->candidates($connection, $table, $now, $endpointIds) as $id) {
            $claim = $connection->transactional(function (Connection $connection) use ($table, $now, $id): ?DeliveryClaim {
                $query = $connection->createQueryBuilder()
                    ->select('id')
                    ->from($table)
                    ->where('id = :id')
                    ->andWhere('status IN (:statuses)')
                    ->andWhere('next_attempt_at <= :now')
                    ->andWhere('(claimed_until IS NULL OR claimed_until < :now)')
                    ->setParameter('id', $id, ParameterType::INTEGER)
                    ->setParameter('statuses', self::OPEN_STATUSES, ArrayParameterType::STRING)
                    ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                ;
                $this->lock($query);
                if (!is_numeric($query->executeQuery()->fetchOne())) {
                    return null;
                }

                $token = bin2hex(random_bytes(16));
                $connection->update(
                    $table,
                    ['claimed_until' => $now->modify(sprintf('+%d seconds', $this->leaseSeconds)), 'claim_token' => $token],
                    ['id' => $id],
                    ['claimed_until' => Types::DATETIME_IMMUTABLE],
                );

                return new DeliveryClaim($id, $token);
            });

            if ($claim instanceof DeliveryClaim) {
                return $claim;
            }
        }

        return null;
    }

    /**
     * True when the claim still belongs to the caller; locks the row until the end of the
     * current transaction.
     */
    public function holds(DeliveryClaim $claim): bool
    {
        $query = $this->entityManager()->getConnection()->createQueryBuilder()
            ->select('claim_token')
            ->from($this->deliveryTable())
            ->where('id = :id')
            ->setParameter('id', $claim->deliveryId)
            ->forUpdate()
        ;

        return $claim->token === $query->executeQuery()->fetchOne();
    }

    /**
     * Self-healing, both ways, so that the state always converges whatever the races:
     *  - open deliveries of a disabled endpoint are suspended (a flush that read the endpoint
     *    as enabled before it was disabled may still fan out to it);
     *  - suspended deliveries of an enabled endpoint are requeued (a suspension racing with a
     *    re-enable).
     * The endpoint state is checked inside each UPDATE (subquery), never from an earlier read,
     * and a delivery with a live claim is never touched.
     *
     * @return int number of deliveries changed
     */
    public function reconcileEndpointStates(?Connection $connection = null): int
    {
        $connection ??= $this->entityManager()->getConnection();
        $table = $this->deliveryTable();
        $endpointTable = $this->entityManager()->getClassMetadata($this->endpointClass)->getTableName();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        // Plain read first: the UPDATEs only run when something may need them.
        $mismatch = $connection->fetchOne(
            sprintf(
                'SELECT d.id FROM %1$s d INNER JOIN %2$s e ON e.id = d.endpoint_id WHERE (e.enabled = ? AND d.status IN (?)) OR (e.enabled = ? AND d.status = ?)',
                $table,
                $endpointTable,
            ),
            [false, self::OPEN_STATUSES, true, WebhookDeliveryInterface::STATUS_SUSPENDED],
            [ParameterType::BOOLEAN, ArrayParameterType::STRING, ParameterType::BOOLEAN, ParameterType::STRING],
        );
        if (false === $mismatch) {
            return 0;
        }

        $changed = $connection->executeStatement(
            sprintf(
                'UPDATE %1$s SET status = ?, next_attempt_at = NULL, claimed_until = NULL, claim_token = NULL WHERE status IN (?) AND (claimed_until IS NULL OR claimed_until < ?) AND endpoint_id IN (SELECT id FROM %2$s WHERE enabled = ?)',
                $table,
                $endpointTable,
            ),
            [WebhookDeliveryInterface::STATUS_SUSPENDED, self::OPEN_STATUSES, $now, false],
            [ParameterType::STRING, ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::BOOLEAN],
        );

        $changed += $connection->executeStatement(
            sprintf(
                'UPDATE %1$s SET status = ?, next_attempt_at = ? WHERE status = ? AND endpoint_id IN (SELECT id FROM %2$s WHERE enabled = ?)',
                $table,
                $endpointTable,
            ),
            [WebhookDeliveryInterface::STATUS_PENDING, $now, WebhookDeliveryInterface::STATUS_SUSPENDED, true],
            [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::STRING, ParameterType::BOOLEAN],
        );

        return (int) $changed;
    }

    /** Gives a claimed delivery back (the attempt could not be recorded). */
    public function release(DeliveryClaim $claim): void
    {
        $this->entityManager()->getConnection()->update(
            $this->deliveryTable(),
            ['claimed_until' => null, 'claim_token' => null],
            ['id' => $claim->deliveryId, 'claim_token' => $claim->token],
        );
    }

    public function isSkipLockedSupported(): bool
    {
        if (null === $this->skipLockedSupported) {
            $query = $this->entityManager()->getConnection()->createQueryBuilder()->select('1')->from('dual_check');

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

    /**
     * @param list<int> $endpointIds
     *
     * @return list<int> due delivery ids, oldest first
     */
    private function candidates(Connection $connection, string $table, \DateTimeImmutable $now, array $endpointIds): array
    {
        $rows = [];
        foreach (self::OPEN_STATUSES as $status) {
            $found = $connection->createQueryBuilder()
                ->select('id', 'next_attempt_at')
                ->from($table)
                ->where('status = :status')
                ->andWhere('next_attempt_at <= :now')
                ->andWhere('(claimed_until IS NULL OR claimed_until < :now)')
                ->andWhere('endpoint_id IN (:endpoints)')
                ->orderBy('next_attempt_at', 'ASC')
                ->addOrderBy('id', 'ASC')
                ->setMaxResults(self::CANDIDATES)
                ->setParameter('status', $status)
                ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                ->setParameter('endpoints', $endpointIds, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchAllAssociative()
            ;
            foreach ($found as $row) {
                $rows[] = ['id' => is_numeric($row['id']) ? (int) $row['id'] : 0, 'at' => is_scalar($row['next_attempt_at']) ? (string) $row['next_attempt_at'] : ''];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']]);

        return array_slice(array_column($rows, 'id'), 0, self::CANDIDATES);
    }

    private function lock(QueryBuilder $query): void
    {
        if ($this->isSkipLockedSupported()) {
            $query->forUpdate(ConflictResolutionMode::SKIP_LOCKED);
        } else {
            $query->forUpdate();
        }
    }

    private function deliveryTable(): string
    {
        return $this->entityManager()->getClassMetadata($this->deliveryClass)->getTableName();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManagerForClass($this->deliveryClass);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException(sprintf('No Doctrine ORM entity manager for "%s".', $this->deliveryClass));
        }

        return $entityManager;
    }
}
