<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Psr\Log\LoggerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorder;

/**
 * Transactional capture (see SPEC.md, "Capture"):
 *
 *  - onFlush: the detectors read the change sets and the recorder persists the events and their
 *    deliveries with computeChangeSet(), so that Doctrine inserts them in the transaction it is
 *    about to open for the business change. No DBAL write here: onFlush runs before
 *    beginTransaction().
 *  - postPersist of an event (inside the transaction, after the insert of the entities it refers
 *    to): the ids unknown at onFlush time (resource_id, customer_id of new orders and customers)
 *    are written with one UPDATE on the same connection.
 */
final readonly class OutboxCaptureListener
{
    /** @param iterable<ChangeDetectorInterface> $detectors */
    public function __construct(
        private iterable $detectors,
        private OutboxRecorder $recorder,
        private LoggerInterface $logger,
        private bool $enabled = true,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $this->recorder->captureDuringFlush($entityManager, function () use ($entityManager, $unitOfWork): void {
            foreach ($this->detectors as $detector) {
                try {
                    foreach ($detector->detect($entityManager, $unitOfWork) as $event) {
                        $this->recorder->record($event);
                    }
                } catch (\Throwable $exception) {
                    // A webhook must never break a checkout: log loudly and let the business change go through.
                    $this->logger->critical('Webhook capture failed in {detector}: {message}', [
                        'detector' => $detector::class,
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]);
                }
            }
        });
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $event = $args->getObject();
        if (!$event instanceof OutboxEventInterface || !$event->resolveReferences()) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $this->writeResolvedReferences($entityManager, $event);
    }

    private function writeResolvedReferences(EntityManagerInterface $entityManager, OutboxEventInterface $event): void
    {
        $metadata = $entityManager->getClassMetadata($event::class);
        $entityManager->getConnection()->update(
            $metadata->getTableName(),
            [
                $metadata->getColumnName('resourceId') => $event->getResourceId(),
                $metadata->getColumnName('customerId') => $event->getCustomerId(),
            ],
            [$metadata->getColumnName('id') => $event->getId()],
        );

        // Keep the unit of work in sync, so that the next flush does not update them again.
        $unitOfWork = $entityManager->getUnitOfWork();
        $unitOfWork->setOriginalEntityProperty(spl_object_id($event), 'resourceId', $event->getResourceId());
        $unitOfWork->setOriginalEntityProperty(spl_object_id($event), 'customerId', $event->getCustomerId());
    }
}
