<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Tmonier\SyliusWebhookOutboxPlugin\Health\BacklogCheck;
use Tmonier\SyliusWebhookOutboxPlugin\Health\DeadDeliveriesCheck;

/*
 * The probes of the plugin, apart from its other services: the extension loads this file only when the health plugin
 * is installed. The autoconfiguration of the health plugin gives them their tag, never tag them by hand.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services
        ->set('tmonier_webhook.health.backlog', BacklogCheck::class)
        ->args([service('tmonier_webhook.repository.webhook_delivery'), service('clock')])
        ->autoconfigure()
    ;
    $services
        ->set('tmonier_webhook.health.dead_deliveries', DeadDeliveriesCheck::class)
        ->args([service('tmonier_webhook.repository.webhook_delivery'), service('tmonier_webhook.repository.webhook_endpoint')])
        ->autoconfigure()
    ;
};
