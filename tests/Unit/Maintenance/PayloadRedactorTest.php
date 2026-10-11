<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Maintenance;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\EnvelopeFactory;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\PayloadRedactor;

final class PayloadRedactorTest extends TestCase
{
    public function testPersonalValuesAreRemovedAtAnyDepth(): void
    {
        $redacted = (new PayloadRedactor())->redact([
            'id' => 12,
            'number' => '000012',
            'customer' => ['id' => 3, 'email' => 'ada@example.com', 'first_name' => 'Ada'],
            'billing_address' => ['street' => '1 rue X', 'city' => 'Paris', 'country_code' => 'FR'],
            'notes' => 'Ring twice',
            'totals' => ['total' => 1000],
        ]);

        self::assertSame([
            'id' => 12,
            'number' => '000012',
            'customer' => ['id' => 3, 'email' => null, 'first_name' => null],
            'billing_address' => ['street' => null, 'city' => null, 'country_code' => 'FR'],
            'notes' => null,
            'totals' => ['total' => 1000],
        ], $redacted);
    }

    public function testEnvelope(): void
    {
        $event = new OutboxEvent('order.paid', 1, new \DateTimeImmutable('2026-10-11 12:00:00', new \DateTimeZone('Europe/Paris')), ['id' => 1, 'name' => 'Crème/brûlée'], 'FASHION_WEB', id: '0199d2f4-0000-7000-8000-000000000000');

        self::assertSame(
            '{"id":"0199d2f4-0000-7000-8000-000000000000","type":"order.paid","version":1,"timestamp":"2026-10-11T10:00:00Z","channel":"FASHION_WEB","data":{"id":1,"name":"Crème/brûlée"}}',
            EnvelopeFactory::encode($event),
        );
    }
}
