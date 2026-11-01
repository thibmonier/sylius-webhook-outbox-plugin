<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * Self-healing reconciliation between endpoint enabled/disabled state and delivery statuses.
 *
 * Open deliveries of a disabled endpoint are suspended; suspended deliveries of an enabled
 * endpoint are requeued. The endpoint state is re-checked inside each UPDATE (subquery),
 * never trusted from the earlier read, and a delivery with a live claim is never touched.
 */
final class EndpointStateReconciler
{
    private const OPEN_STATUSES = [WebhookDeliveryInterface::STATUS_PENDING, WebhookDeliveryInterface::STATUS_FAILED];

    private ?float $lastReconciliation = null;

    /**
     * @param class-string<WebhookDeliveryInterface> $deliveryClass
     * @param class-string<WebhookEndpointInterface> $endpointClass
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ClockInterface $clock,
        private readonly string $deliveryClass,
        private readonly string $endpointClass,
        private readonly float $reconcileInterval = 30,
    ) {
    }

    /**
     * @return int number of deliveries changed
     */
    public function reconcileEndpointStates(?Connection $connection = null): int
    {
        $connection ??= $this->entityManager()->getConnection();
        $this->lastReconciliation = microtime(true);
        $table = $this->entityManager()->getClassMetadata($this->deliveryClass)->getTableName();
        $endpointTable = $this->entityManager()->getClassMetadata($this->endpointClass)->getTableName();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $changed = 0;

        $disabled = $this->ids($connection->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE enabled = ?', $endpointTable), [false], [ParameterType::BOOLEAN]));
        if ([] !== $disabled && $this->exists($connection, $table, $disabled, self::OPEN_STATUSES)) {
            $changed += (int) $connection->executeStatement(
                sprintf(
                    'UPDATE %1$s SET status = ?, next_attempt_at = NULL, claimed_until = NULL, claim_token = NULL WHERE endpoint_id IN (?) AND status IN (?) AND (claimed_until IS NULL OR claimed_until < ?) AND endpoint_id IN (SELECT id FROM %2$s WHERE enabled = ?)',
                    $table,
                    $endpointTable,
                ),
                [WebhookDeliveryInterface::STATUS_SUSPENDED, $disabled, self::OPEN_STATUSES, $now, false],
                [ParameterType::STRING, ArrayParameterType::INTEGER, ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE, ParameterType::BOOLEAN],
            );
        }

        $enabled = $this->ids($connection->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE enabled = ?', $endpointTable), [true], [ParameterType::BOOLEAN]));
        if ([] !== $enabled && $this->exists($connection, $table, $enabled, [WebhookDeliveryInterface::STATUS_SUSPENDED])) {
            $changed += (int) $connection->executeStatement(
                sprintf(
                    'UPDATE %1$s SET status = ?, next_attempt_at = ? WHERE endpoint_id IN (?) AND status = ? AND endpoint_id IN (SELECT id FROM %2$s WHERE enabled = ?)',
                    $table,
                    $endpointTable,
                ),
                [WebhookDeliveryInterface::STATUS_PENDING, $now, $enabled, WebhookDeliveryInterface::STATUS_SUSPENDED, true],
                [ParameterType::STRING, Types::DATETIME_IMMUTABLE, ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::BOOLEAN],
            );
        }

        return $changed;
    }

    public function reconcileIfDue(?Connection $connection = null): bool
    {
        if (null !== $this->lastReconciliation && microtime(true) - $this->lastReconciliation < $this->reconcileInterval) {
            return false;
        }
        $this->reconcileEndpointStates($connection);

        return true;
    }

    /**
     * @param list<int> $endpointIds
     * @param list<string> $statuses
     */
    private function exists(Connection $connection, string $table, array $endpointIds, array $statuses): bool
    {
        return false !== $connection->fetchOne(
            $connection->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id FROM %s WHERE endpoint_id IN (?) AND status IN (?)', $table), 1),
            [$endpointIds, $statuses],
            [ArrayParameterType::INTEGER, ArrayParameterType::STRING],
        );
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<int>
     */
    private function ids(array $values): array
    {
        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $values);
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
