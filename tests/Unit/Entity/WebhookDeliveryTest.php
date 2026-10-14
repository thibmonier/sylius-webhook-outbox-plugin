<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDelivery;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;

final class WebhookDeliveryTest extends TestCase
{
    public function testLifecycle(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 10:00:00', new \DateTimeZone('UTC'));
        $delivery = new WebhookDelivery($this->event(), new WebhookEndpoint(), $now);

        self::assertSame(WebhookDeliveryInterface::STATUS_PENDING, $delivery->getStatus());
        self::assertTrue($delivery->isDue($now));
        self::assertTrue($delivery->canBeRetriedNow());
        self::assertFalse($delivery->canBeReplayed());

        $delivery->recordAttempt(new DeliveryAttempt($now, 120, 500, 'boom'));
        $delivery->scheduleRetry($now->modify('+5 seconds'));
        self::assertSame(WebhookDeliveryInterface::STATUS_FAILED, $delivery->getStatus());
        self::assertFalse($delivery->isDue($now));
        self::assertTrue($delivery->isDue($now->modify('+5 seconds')));

        self::assertTrue($delivery->retryNow($now));
        self::assertTrue($delivery->isDue($now));

        $delivery->recordAttempt(new DeliveryAttempt($now, 80, 200, 'ok'));
        $delivery->markDelivered($now);
        self::assertSame(2, $delivery->getAttempts());
        self::assertNull($delivery->getNextAttemptAt());
        self::assertFalse($delivery->retryNow($now));
        self::assertTrue($delivery->canBeReplayed());
        self::assertSame([200, 500], array_column($delivery->getAttemptHistory(), 'status_code'), 'Newest first.');

        $delivery->eraseResponses();
        self::assertNull($delivery->getLastResponseBody());
        self::assertSame([null, null], array_column($delivery->getAttemptHistory(), 'response_body'));
        self::assertSame([200, 500], array_column($delivery->getAttemptHistory(), 'status_code'));
    }

    public function testHistoryKeepsTheLastAttempts(): void
    {
        $now = new \DateTimeImmutable();
        $delivery = new WebhookDelivery($this->event(), new WebhookEndpoint(), $now);
        for ($i = 0; $i < 15; ++$i) {
            $delivery->recordAttempt(new DeliveryAttempt($now, $i, 500));
        }

        self::assertSame(15, $delivery->getAttempts());
        self::assertCount(WebhookDeliveryInterface::HISTORY_SIZE, $delivery->getAttemptHistory());
        self::assertSame(14, $delivery->getAttemptHistory()[0]['duration_ms']);
    }

    public function testAnonymizedEventsCannotBeReplayed(): void
    {
        $event = $this->event();
        $delivery = new WebhookDelivery($event, new WebhookEndpoint(), new \DateTimeImmutable());
        $delivery->markDead();
        self::assertTrue($delivery->canBeReplayed());

        $event->markAnonymized(new \DateTimeImmutable());
        self::assertFalse($delivery->canBeReplayed());
    }

    public function testEndpointSubscriptionsAndSecretRedaction(): void
    {
        $endpoint = new WebhookEndpoint();
        $endpoint->setSecret('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');
        $endpoint->setEventTypes(['order.paid', 'order.paid', 'customer.created']);
        $endpoint->setChannelCodes(['WEB']);

        self::assertSame(['order.paid', 'customer.created'], $endpoint->getEventTypes());
        self::assertTrue($endpoint->isSubscribedTo('order.paid'));
        self::assertFalse($endpoint->isSubscribedTo('order.placed'));
        self::assertTrue($endpoint->acceptsChannel('WEB'));
        self::assertTrue($endpoint->acceptsChannel(null));
        self::assertFalse($endpoint->acceptsChannel('B2B'));
        $endpoint->setUrl('https://hooks.zapier.com/hooks/catch/123456/abc1de2/?token=s3cr3t');
        $dump = print_r($endpoint, true);
        self::assertStringNotContainsString('MfKQ9r8', $dump);
        self::assertStringNotContainsString('abc1de2', $dump);
        self::assertStringNotContainsString('s3cr3t', $dump);

        $endpoint->disable('410 Gone', new \DateTimeImmutable());
        self::assertFalse($endpoint->isEnabled());
        $endpoint->setEnabled(true);
        self::assertNull($endpoint->getDisabledReason());
    }

    private function event(): OutboxEvent
    {
        return new OutboxEvent('order.paid', 1, new \DateTimeImmutable(), ['id' => 1]);
    }
}
