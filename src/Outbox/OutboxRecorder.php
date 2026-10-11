<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\RetryPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;

final class OutboxRecorder implements OutboxRecorderInterface
{
    /** Entity manager being flushed: set by the capture listener during onFlush. */
    private ?EntityManagerInterface $flushing = null;

    /**
     * @param class-string<OutboxEventInterface> $eventClass
     * @param class-string<WebhookDeliveryInterface> $deliveryClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookEndpointRepositoryInterface $endpointRepository,
        private readonly EventTypeRegistryInterface $eventTypes,
        private readonly RetryPolicy $retryPolicy,
        private readonly ClockInterface $clock,
        private readonly string $eventClass,
        private readonly string $deliveryClass,
        private readonly bool $enabled = true,
    ) {
    }

    public function record(WebhookEvent $event, ?WebhookEndpointInterface $endpoint = null): ?OutboxEventInterface
    {
        if (null === $endpoint) {
            if (!$this->enabled || !$this->eventTypes->isEnabled($event->type)) {
                return null;
            }
            $endpoints = $this->endpointRepository->findSubscribed($event->type, $event->channelCode);
        } else {
            $endpoints = [$endpoint];
        }

        if ([] === $endpoints) {
            return null;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entityManager = $this->flushing ?? $this->entityManager;
        $uow = $entityManager->getUnitOfWork();

        $eventClass = $this->eventClass;
        $outboxEvent = new $eventClass(
            $event->type,
            $this->eventTypes->get($event->type)->version,
            $now,
            new DeferredPayload($event->data),
            $event->channelCode,
            $event->resourceType,
            $event->resourceId,
            $event->customerId,
        );
        $entityManager->persist($outboxEvent);
        if (null !== $this->flushing) {
            $uow->computeChangeSet($entityManager->getClassMetadata($outboxEvent::class), $outboxEvent);
        }

        $firstAttemptAt = $now->modify(sprintf('+%d seconds', $this->retryPolicy->firstAttemptDelay()));
        $deliveryClass = $this->deliveryClass;
        foreach ($endpoints as $target) {
            $delivery = new $deliveryClass($outboxEvent, $target, $now, null, $firstAttemptAt);
            $entityManager->persist($delivery);
            if (null !== $this->flushing) {
                $uow->computeChangeSet($entityManager->getClassMetadata($delivery::class), $delivery);
            }
        }

        return $outboxEvent;
    }

    /**
     * Runs the detectors of a flush: what they record is scheduled in that same flush.
     *
     * @param callable(): void $capture
     */
    public function captureDuringFlush(EntityManagerInterface $entityManager, callable $capture): void
    {
        $previous = $this->flushing;
        $this->flushing = $entityManager;

        try {
            $capture();
        } finally {
            $this->flushing = $previous;
        }
    }
}
