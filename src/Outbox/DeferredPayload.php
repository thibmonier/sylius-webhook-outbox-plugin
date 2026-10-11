<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

/**
 * Payload of an event recorded during a flush. Doctrine's "json" type encodes it when the
 * outbox row is inserted, i.e. after the entities inserted earlier in the same flush got their
 * ids: the LazyValue references are resolved at that moment, inside the transaction.
 */
final class DeferredPayload implements \JsonSerializable
{
    /** @var array<string, mixed>|null */
    private ?array $resolved = null;

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if (null === $this->resolved) {
            /** @var array<string, mixed> $resolved */
            $resolved = LazyValue::resolveAll($this->data);
            $this->resolved = $resolved;
        }

        return $this->resolved;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
