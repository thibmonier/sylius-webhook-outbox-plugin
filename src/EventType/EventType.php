<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventType;

/**
 * Definition of a webhook event type: "order.placed", version 1, labelled by a translation key.
 */
final readonly class EventType
{
    public function __construct(
        public string $type,
        public int $version = 1,
        public ?string $label = null,
        /** Endpoints can subscribe to it (false for technical types such as webhook.ping). */
        public bool $subscribable = true,
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $type)) {
            throw new \InvalidArgumentException(sprintf('Invalid webhook event type "%s": expected "resource.action" in snake case.', $type));
        }
        if ($version < 1) {
            throw new \InvalidArgumentException(sprintf('The version of the webhook event type "%s" must be positive.', $type));
        }
    }

    public function getLabel(): string
    {
        return $this->label ?? 'tmonier_webhook.event_type.' . $this->type;
    }
}
