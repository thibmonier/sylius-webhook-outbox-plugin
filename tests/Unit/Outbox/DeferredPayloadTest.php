<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\DeferredPayload;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

final class DeferredPayloadTest extends TestCase
{
    public function testIdsAreResolvedWhenEncoded(): void
    {
        $entity = new class() {
            public ?int $id = null;

            public function getId(): ?int
            {
                return $this->id;
            }
        };

        $reference = LazyValue::idOf($entity);
        self::assertInstanceOf(LazyValue::class, $reference);
        $payload = new DeferredPayload(['id' => $reference, 'lines' => [['id' => $reference]], 'name' => 'x']);

        // Doctrine encodes the payload when it inserts the row, after the entity insert.
        $entity->id = 42;
        self::assertSame('{"id":42,"lines":[{"id":42}],"name":"x"}', json_encode($payload));
        self::assertSame(42, $payload->toArray()['id']);
    }

    public function testKnownIdsAreUsedDirectly(): void
    {
        $entity = new class() {
            public function getId(): int
            {
                return 7;
            }
        };

        self::assertSame(7, LazyValue::idOf($entity));
        self::assertInstanceOf(LazyValue::class, LazyValue::idOf(new \stdClass()));
    }

    public function testNullIsNotMemoized(): void
    {
        $id = null;
        $value = new LazyValue(static function () use (&$id): ?int {
            return $id;
        });

        self::assertNull($value->resolve());
        $id = 3;
        self::assertSame(3, $value->resolve());
    }

    public function testEventReferencesResolvedAfterInsert(): void
    {
        $customer = new class() {
            public ?int $id = null;

            public function getId(): ?int
            {
                return $this->id;
            }
        };
        $reference = LazyValue::idOf($customer);
        $event = new OutboxEvent('customer.created', 1, new \DateTimeImmutable('2026-10-11 12:00:00', new \DateTimeZone('Europe/Paris')), ['a' => 1], null, 'customer', $reference, $reference);

        self::assertNull($event->getResourceId());
        self::assertFalse($event->resolveReferences());
        $customer->id = 5;
        self::assertTrue($event->resolveReferences());
        self::assertSame('5', $event->getResourceId());
        self::assertSame(5, $event->getCustomerId());
        self::assertFalse($event->resolveReferences(), 'Only once.');
        self::assertSame('2026-10-11 10:00:00', $event->getOccurredAt()->format('Y-m-d H:i:s'), 'Stored in UTC.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $event->getId(), 'UUIDv7.');
    }
}
