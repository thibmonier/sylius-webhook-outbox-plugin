<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;

/**
 * Deliveries that gave up (dead letters) and endpoints disabled automatically after a "410 Gone": both need a human.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class DeadDeliveriesCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly WebhookEndpointRepositoryInterface $endpoints,
    ) {
    }

    public function identifier(): string
    {
        return 'webhook_outbox.dead_deliveries';
    }

    public function category(): HealthCategory
    {
        return HealthCategory::Plugins;
    }

    public function run(): HealthResult
    {
        $this->deliveries->countDeadUpTo(1);
        $this->endpoints->countDisabledAutomatically();

        return HealthResult::unknown(new TranslatableMessage('tmonier_webhook.health.dead.ok'));
    }
}
