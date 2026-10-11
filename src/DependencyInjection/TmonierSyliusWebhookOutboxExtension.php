<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeProviderInterface;

final class TmonierSyliusWebhookOutboxExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function getAlias(): string
    {
        return 'tmonier_sylius_webhook_outbox';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $this->registerResources('tmonier_webhook', $config['driver'], $config['models'], $container);

        $container->setParameter('tmonier_webhook.enabled', $config['enabled']);
        $container->setParameter('tmonier_webhook.events', $config['events']);
        $container->setParameter('tmonier_webhook.retry_schedule', array_values($config['retry_schedule']));
        $container->setParameter('tmonier_webhook.retry_jitter', $config['retry_jitter']);
        $container->setParameter('tmonier_webhook.timeout', $config['timeout']);
        $container->setParameter('tmonier_webhook.max_duration', $config['max_duration']);
        $container->setParameter('tmonier_webhook.response_body_max_bytes', $config['response_body_max_bytes']);
        $container->setParameter('tmonier_webhook.retention_days', $config['retention_days']);
        $container->setParameter('tmonier_webhook.allow_http_endpoints', $config['allow_http_endpoints']);
        $container->setParameter('tmonier_webhook.allowed_private_networks', array_values(array_unique($config['allowed_private_networks'])));
        // A claimed delivery is reclaimed when its worker did not report within this lease.
        $container->setParameter('tmonier_webhook.claim_lease', (int) ceil($config['max_duration']) + 60);

        $container->registerForAutoconfiguration(EventTypeProviderInterface::class)->addTag('tmonier_webhook.event_type_provider');
        $container->registerForAutoconfiguration(ChangeDetectorInterface::class)->addTag('tmonier_webhook.change_detector');

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.php');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias()));
        foreach ($config['models'] as $name => $model) {
            $container->setParameter(sprintf('tmonier_webhook.model.%s.class', $name), $model['classes']['model']);
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'TmonierSyliusWebhookOutboxPlugin' => [
                        'type' => 'xml',
                        'dir' => 'config/doctrine',
                        'prefix' => 'Tmonier\SyliusWebhookOutboxPlugin\Entity',
                        'is_bundle' => true,
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Tmonier\SyliusWebhookOutboxPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@TmonierSyliusWebhookOutboxPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return [
            'Sylius\Bundle\CoreBundle\Migrations',
        ];
    }
}
