<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Maintenance;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;

/**
 * Erasure hook (GDPR art. 17): redacts the payloads of the customer's events and removes the
 * response excerpts of their deliveries (endpoints may echo the data back).
 *
 * Replays of anonymized events are refused: resending a redacted body under the same
 * webhook-id would either be ignored by the receiver (deduplication) or overwrite its data
 * with blanks. Pending retries are sent with the redacted body.
 */
final class CustomerWebhookDataAnonymizer
{
    public function __construct(
        private readonly OutboxEventRepositoryInterface $eventRepository,
        private readonly WebhookDeliveryRepositoryInterface $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PayloadRedactor $redactor,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * One transaction; delivery rows are re-read with a locking read (FOR UPDATE) before their
     * responses are erased, so that a worker recording an attempt at the same time neither
     * loses its attempt nor restores the erased responses (it re-reads under the same lock).
     *
     * @return int number of anonymized events
     */
    public function anonymize(int $customerId): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        return (int) $this->entityManager->wrapInTransaction(function () use ($customerId, $now): int {
            $count = 0;
            foreach ($this->eventRepository->findByCustomerId($customerId) as $event) {
                if ($event->isAnonymized()) {
                    continue;
                }

                /** @var array<string, mixed> $payload */
                $payload = $this->redactor->redact($event->getPayload());
                $event->replacePayload($payload);
                $event->markAnonymized($now);
                foreach ($this->deliveryRepository->findByEvent($event) as $delivery) {
                    // A locking read: under REPEATABLE READ a plain refresh() would return the
                    // snapshot of the first query and miss an attempt committed since then.
                    $this->entityManager->refresh($delivery, LockMode::PESSIMISTIC_WRITE);
                    $delivery->eraseResponses();
                }
                ++$count;
            }

            $this->entityManager->flush();

            return $count;
        });
    }
}
