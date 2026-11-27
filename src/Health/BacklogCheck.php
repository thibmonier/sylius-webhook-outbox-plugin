<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Health;

use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;

/**
 * Is the dispatcher still draining the outbox? Reads the age of the oldest pending delivery that is due.
 * Loaded only when the health plugin is installed: this class implements its interface.
 */
final class BacklogCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly WebhookDeliveryRepositoryInterface $deliveries,
        private readonly ClockInterface $clock,
    ) {
    }

    public function identifier(): string
    {
        return 'webhook_outbox.backlog';
    }

    public function category(): HealthCategory
    {
        return HealthCategory::Plugins;
    }

    public function run(): HealthResult
    {
        $this->deliveries->oldestPendingDueAt(\DateTimeImmutable::createFromInterface($this->clock->now()));

        return HealthResult::unknown(new TranslatableMessage('tmonier_webhook.health.backlog.ok'));
    }
}
