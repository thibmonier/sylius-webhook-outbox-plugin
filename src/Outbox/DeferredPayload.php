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
        if (null !== $this->resolved) {
            return $this->resolved;
        }

        $complete = true;
        /** @var array<string, mixed> $resolved */
        $resolved = self::resolve($this->data, $complete);
        // An early read (before the insert of the referenced entities) must not freeze nulls.
        if ($complete) {
            $this->resolved = $resolved;
        }

        return $resolved;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function resolve(array $data, bool &$complete): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof LazyValue) {
                $data[$key] = $value->resolve();
                if (null === $data[$key]) {
                    $complete = false;
                }
            } elseif (is_array($value)) {
                $data[$key] = self::resolve($value, $complete);
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
