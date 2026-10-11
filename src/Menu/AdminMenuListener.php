<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * "Integrations" group of the admin menu (created if no other plugin did), with the endpoints,
 * the deliveries and the dead letters.
 */
final class AdminMenuListener
{
    public const GROUP = 'integrations';

    public function __invoke(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();
        $group = $menu->getChild(self::GROUP) ?? $menu
            ->addChild(self::GROUP)
            ->setLabel('tmonier_webhook.ui.integrations')
            ->setLabelAttribute('icon', 'tabler:plug-connected')
        ;

        $group
            ->addChild('tmonier_webhook_endpoints', ['route' => 'tmonier_webhook_admin_webhook_endpoint_index', 'extras' => ['routes' => [
                ['route' => 'tmonier_webhook_admin_webhook_endpoint_create'],
                ['route' => 'tmonier_webhook_admin_webhook_endpoint_update'],
            ]]])
            ->setLabel('tmonier_webhook.ui.webhook_endpoints')
            ->setLabelAttribute('icon', 'tabler:webhook')
        ;
        $group
            ->addChild('tmonier_webhook_deliveries', ['route' => 'tmonier_webhook_admin_webhook_delivery_index', 'extras' => ['routes' => [
                ['route' => 'tmonier_webhook_admin_webhook_delivery_show'],
            ]]])
            ->setLabel('tmonier_webhook.ui.webhook_deliveries')
            ->setLabelAttribute('icon', 'tabler:send')
        ;
        $group
            ->addChild('tmonier_webhook_dead_letters', ['route' => 'tmonier_webhook_admin_webhook_delivery_dead_letters'])
            ->setLabel('tmonier_webhook.ui.dead_letters')
            ->setLabelAttribute('icon', 'tabler:mail-x')
        ;
    }
}
