<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

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
}
