<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Repository;

use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

class WebhookDeliveryRepository extends EntityRepository implements WebhookDeliveryRepositoryInterface
{
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
