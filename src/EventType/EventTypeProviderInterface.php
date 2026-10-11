<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventType;

/**
 * Declares webhook event types. Autoconfigured with the "tmonier_webhook.event_type_provider"
 * tag: other plugins implement it to add their types (e.g. "quote.accepted").
 */
interface EventTypeProviderInterface
{
    /** @return iterable<EventType> */
    public function getEventTypes(): iterable;
}
