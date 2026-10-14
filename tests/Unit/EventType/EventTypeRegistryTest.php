<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\EventType;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventType;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeProviderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistry;

final class EventTypeRegistryTest extends TestCase
{
    public function testCoreAndThirdPartyTypes(): void
    {
        $registry = new EventTypeRegistry([new CoreEventTypeProvider(), $this->provider(new EventType('quote.accepted', 2))], [
            'product_variant.stock_changed' => ['enabled' => false],
        ]);

        self::assertTrue($registry->has('quote.accepted'));
        self::assertSame(2, $registry->get('quote.accepted')->version);
        self::assertSame('tmonier_webhook.event_type.quote.accepted', $registry->get('quote.accepted')->getLabel());
        self::assertTrue($registry->isEnabled('order.placed'));
        self::assertFalse($registry->isEnabled('product_variant.stock_changed'));
        self::assertFalse($registry->isEnabled('unknown.type'));
        self::assertArrayNotHasKey('webhook.ping', $registry->subscribable());
        self::assertArrayNotHasKey('product_variant.stock_changed', $registry->subscribable());
        self::assertArrayHasKey('webhook.ping', $registry->all());
        self::assertSame(array_keys($registry->all()), array_values(array_unique(array_keys($registry->all()))));
    }

    public function testDuplicatesAndInvalidNames(): void
    {
        $registry = new EventTypeRegistry([$this->provider(new EventType('order.paid')), new CoreEventTypeProvider()]);

        try {
            $registry->all();
            self::fail('Duplicate accepted.');
        } catch (\LogicException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        new EventType('OrderPaid');
    }

    public function testUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new EventTypeRegistry([]))->get('order.paid');
    }

    private function provider(EventType ...$types): EventTypeProviderInterface
    {
        return new class(array_values($types)) implements EventTypeProviderInterface {
            /** @param list<EventType> $types */
            public function __construct(private readonly array $types)
            {
            }

            public function getEventTypes(): iterable
            {
                return $this->types;
            }
        };
    }
}
