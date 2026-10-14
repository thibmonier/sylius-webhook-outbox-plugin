<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture;

use Psr\Log\LoggerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;

/**
 * Isolates the events of one entity: a failing normalization is logged with the entity class
 * and id, and the other entities of the flush still get their events. A webhook bug never
 * breaks the business flush.
 */
final readonly class EntityGuard
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * @param callable(): iterable<WebhookEvent> $build
     *
     * @return list<WebhookEvent>
     */
    public function events(object $entity, callable $build): array
    {
        return $this->call($entity, static function () use ($build): array {
            $events = [];
            foreach ($build() as $event) {
                $events[] = $event;
            }

            return $events;
        }) ?? [];
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T|null null when the callback failed (logged)
     */
    public function call(object $entity, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->logger->critical('Webhook capture failed for {entity} #{id}: {message}', [
                'entity' => $entity::class,
                'id' => method_exists($entity, 'getId') ? $entity->getId() : null,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
