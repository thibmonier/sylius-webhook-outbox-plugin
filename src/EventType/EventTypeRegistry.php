<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventType;

final class EventTypeRegistry implements EventTypeRegistryInterface
{
    /** @var array<string, EventType>|null */
    private ?array $types = null;

    /**
     * @param iterable<EventTypeProviderInterface> $providers
     * @param array<string, array{enabled: bool}> $eventsConfig the "events" configuration node
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly array $eventsConfig = [],
    ) {
    }

    public function has(string $type): bool
    {
        return isset($this->load()[$type]);
    }

    public function get(string $type): EventType
    {
        return $this->load()[$type] ?? throw new \InvalidArgumentException(sprintf('Unknown webhook event type "%s".', $type));
    }

    public function isEnabled(string $type): bool
    {
        return $this->has($type) && ($this->eventsConfig[$type]['enabled'] ?? true);
    }

    public function all(): array
    {
        return $this->load();
    }

    public function subscribable(): array
    {
        return array_filter(
            $this->load(),
            fn (EventType $type): bool => $type->subscribable && $this->isEnabled($type->type),
        );
    }

    /** @return array<string, EventType> */
    private function load(): array
    {
        if (null !== $this->types) {
            return $this->types;
        }

        $types = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getEventTypes() as $eventType) {
                if (isset($types[$eventType->type])) {
                    throw new \LogicException(sprintf('The webhook event type "%s" is declared twice.', $eventType->type));
                }
                $types[$eventType->type] = $eventType;
            }
        }
        ksort($types);

        return $this->types = $types;
    }
}
