<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

/**
 * A payload value known only once the flush has inserted the entities (database ids, admin URLs
 * of new orders and customers). Resolved when the outbox row is inserted, which happens after
 * the entities it refers to in the same flush (see SPEC.md, "Spike").
 */
final class LazyValue
{
    private bool $resolved = false;

    private mixed $value = null;

    /** @param \Closure(): mixed $resolver */
    public function __construct(private readonly \Closure $resolver)
    {
    }

    /**
     * The entity id if it is already known, a lazy reference otherwise.
     */
    public static function idOf(object $entity): int|string|self|null
    {
        $id = self::readId($entity);
        if (null !== $id) {
            return $id;
        }

        return new self(static fn (): int|string|null => self::readId($entity));
    }

    public function resolve(): mixed
    {
        if (!$this->resolved) {
            $this->value = ($this->resolver)();
            // Null may only mean "not inserted yet": ask again next time.
            $this->resolved = null !== $this->value;
        }

        return $this->value;
    }

    /**
     * Replaces every LazyValue of a nested array by its value.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function resolveAll(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof self) {
                $data[$key] = $value->resolve();
            } elseif (is_array($value)) {
                $data[$key] = self::resolveAll($value);
            }
        }

        return $data;
    }

    private static function readId(object $entity): int|string|null
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }
        $id = $entity->getId();

        return is_int($id) || is_string($id) ? $id : null;
    }
}
