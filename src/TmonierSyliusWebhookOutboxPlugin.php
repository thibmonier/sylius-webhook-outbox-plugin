<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin;

use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\VarDumper\Cloner\AbstractCloner;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\VarDumper\WebhookEndpointCaster;

final class TmonierSyliusWebhookOutboxPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function boot(): void
    {
        $redactor = $this->container?->get('tmonier_webhook.url_redactor', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        if ($redactor instanceof UrlRedactor) {
            UrlRedactor::setDefault($redactor);
        }

        // dump(), the profiler and the toolbar must not show endpoint secrets nor raw URLs.
        if (class_exists(AbstractCloner::class)) {
            AbstractCloner::$defaultCasters[WebhookEndpointInterface::class] = WebhookEndpointCaster::cast(...);
        }
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
