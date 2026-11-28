<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Health;

use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthActionLink;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;

/**
 * Deliveries that gave up (dead letters) and endpoints disabled automatically after a "410 Gone": both need a human.
 * The dead deliveries are counted up to 500, beyond which the summary says "500+". Loaded only when the health plugin is
 * installed: this class implements its interface.
 */
final class DeadDeliveriesCheck implements HealthCheckInterface
{
    private const COUNT_LIMIT = 500;

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
        $dead = $this->deliveries->countDeadUpTo(self::COUNT_LIMIT + 1);
        $disabled = $this->endpoints->countDisabledAutomatically();
        if (0 === $dead && 0 === $disabled) {
            return HealthResult::ok(new TranslatableMessage('tmonier_webhook.health.dead.ok'));
        }

        return HealthResult::warning(
            new TranslatableMessage('tmonier_webhook.health.dead.found', [
                '%dead%' => $dead > self::COUNT_LIMIT ? self::COUNT_LIMIT . '+' : (string) $dead,
                '%disabled%' => (string) $disabled,
            ]),
            action: new HealthActionLink($dead > 0 ? 'tmonier_webhook_admin_webhook_delivery_dead_letters' : 'tmonier_webhook_admin_webhook_endpoint_index'),
        );
    }
}
