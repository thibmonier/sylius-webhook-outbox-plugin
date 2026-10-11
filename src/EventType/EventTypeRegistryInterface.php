<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventType;

interface EventTypeRegistryInterface
{
    public function has(string $type): bool;

    /** @throws \InvalidArgumentException when the type is unknown */
    public function get(string $type): EventType;

    /** Known and not disabled in the configuration. */
    public function isEnabled(string $type): bool;

    /** @return array<string, EventType> by type, sorted */
    public function all(): array;

    /** @return array<string, EventType> enabled types endpoints can subscribe to, by type */
    public function subscribable(): array;
}
