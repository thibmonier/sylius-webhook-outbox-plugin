<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * Public API to record an event, for other plugins.
 *
 * Outside a flush, record() persists the event and its deliveries: they are written by your
 * next flush, in the same transaction as your own changes. Call it before flushing the change
 * the event describes. Inside a flush (detectors), the capture listener handles it.
 */
interface OutboxRecorderInterface
{
    /**
     * @param WebhookEndpointInterface|null $endpoint deliver to this endpoint only, whatever its
     *                                                subscriptions (pings)
     *
     * @return OutboxEventInterface|null null when capture is disabled, the type is disabled or no
     *                                   enabled endpoint subscribes to it
     */
    public function record(WebhookEvent $event, ?WebhookEndpointInterface $endpoint = null): ?OutboxEventInterface;
}
