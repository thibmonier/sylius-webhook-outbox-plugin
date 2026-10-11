<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

class OutboxEventRepository extends EntityRepository implements OutboxEventRepositoryInterface
{
    public function findByCustomerId(int $customerId): array
    {
        /** @var list<OutboxEventInterface> $events */
        $events = $this->createQueryBuilder('o')
            ->andWhere('o.customerId = :customerId')
            ->setParameter('customerId', $customerId)
            ->getQuery()
            ->getResult()
        ;

        return $events;
    }

    public function purgeBefore(\DateTimeImmutable $before, int $limit): int
    {
        /** @var list<string> $ids */
        $ids = $this->purgeableQuery($before)
            ->select('o.id')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult()
        ;
        if ([] === $ids) {
            return 0;
        }

        // DBAL: deliveries go with their event (ON DELETE CASCADE), without loading them.
        $connection = $this->getEntityManager()->getConnection();
        $table = $this->getClassMetadata()->getTableName();

        return (int) $connection->executeStatement(
            sprintf('DELETE FROM %s WHERE id IN (?)', $table),
            [$ids],
            [ArrayParameterType::STRING],
        );
    }

    public function countPurgeableBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->purgeableQuery($before)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    private function purgeableQuery(\DateTimeImmutable $before): QueryBuilder
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.occurredAt < :before')
            ->andWhere(sprintf(
                'NOT EXISTS (SELECT d.id FROM %s d WHERE d.event = o AND d.status IN (:open))',
                WebhookDeliveryInterface::class,
            ))
            ->setParameter('before', $before->setTimezone(new \DateTimeZone('UTC')), 'datetime_immutable')
            ->setParameter('open', [WebhookDeliveryInterface::STATUS_PENDING, WebhookDeliveryInterface::STATUS_FAILED])
        ;
    }
}
