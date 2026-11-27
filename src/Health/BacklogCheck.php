<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Health;

use Psr\Clock\ClockInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthActionLink;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;

/**
 * Is the dispatcher still draining the outbox? Reads the age of the oldest pending delivery that is due: one aggregate
 * query on the "status, next_attempt_at, id" index, no entity loaded. Loaded only when the health plugin is installed:
 * this class implements its interface.
 */
final class BacklogCheck implements HealthCheckInterface
{
    /** Up to 15 minutes late: a dispatcher between two runs of a cron every minute, or a retry in flight. */
    private const WARNING_AFTER_SECONDS = 900;

    private const CRITICAL_AFTER_SECONDS = 3600;

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
        $now = $this->clock->now();
        $dueSince = $this->deliveries->oldestPendingDueAt($now);
        if (null === $dueSince) {
            return $this->ok();
        }

        $age = $now->getTimestamp() - $dueSince->getTimestamp();
        if ($age <= self::WARNING_AFTER_SECONDS) {
            return $this->ok();
        }

        $summary = new TranslatableMessage('tmonier_webhook.health.backlog.late', ['%minutes%' => (int) ceil($age / 60)]);
        $action = new HealthActionLink('tmonier_webhook_admin_webhook_delivery_index');

        return $age > self::CRITICAL_AFTER_SECONDS
            ? HealthResult::critical($summary, action: $action)
            : HealthResult::warning($summary, action: $action);
    }

    private function ok(): HealthResult
    {
        return HealthResult::ok(new TranslatableMessage('tmonier_webhook.health.backlog.ok'));
    }
}
