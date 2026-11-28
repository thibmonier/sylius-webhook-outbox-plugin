<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

class WebhookDeliveryRepository extends EntityRepository implements WebhookDeliveryRepositoryInterface
{
    /** Grid of the deliveries; the dead letters grid passes the "dead" status. */
    public function createListQueryBuilder(?string $status = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('event', 'endpoint')
            ->innerJoin('o.event', 'event')
            ->innerJoin('o.endpoint', 'endpoint')
        ;
        if (null !== $status) {
            $queryBuilder->andWhere('o.status = :status')->setParameter('status', $status);
        }

        return $queryBuilder;
    }

    public function findByEvent(OutboxEventInterface $event): array
    {
        /** @var list<WebhookDeliveryInterface> $deliveries */
        $deliveries = $this->createQueryBuilder('o')
            ->andWhere('o.event = :event')
            ->setParameter('event', $event)
            ->orderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        return $deliveries;
    }

    public function countByStatus(): array
    {
        /** @var list<array{status: string, total: int|string}> $rows */
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status, COUNT(o.id) AS total')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult()
        ;

        $counts = array_fill_keys(WebhookDeliveryInterface::STATUSES, 0);
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function oldestPendingDueAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $oldest = $this->createQueryBuilder('o')
            ->select('MIN(o.nextAttemptAt)')
            ->andWhere('o.status = :status')
            ->andWhere('o.nextAttemptAt <= :now')
            ->setParameter('status', WebhookDeliveryInterface::STATUS_PENDING)
            ->setParameter('now', $now->setTimezone(new \DateTimeZone('UTC')), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult()
        ;

        return is_string($oldest) ? new \DateTimeImmutable($oldest, new \DateTimeZone('UTC')) : null;
    }

    public function countDeadUpTo(int $limit): int
    {
        // The ids only, at most $limit of them, along the "status, next_attempt_at, id" index: the table is never counted whole.
        return count($this->createQueryBuilder('o')
            ->select('o.id')
            ->andWhere('o.status = :status')
            ->setParameter('status', WebhookDeliveryInterface::STATUS_DEAD)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult());
    }
}
