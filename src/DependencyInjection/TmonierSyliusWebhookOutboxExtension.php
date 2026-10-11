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
        $config = self::config($this->processConfiguration(new Configuration(), $configs));

        $this->registerResources('tmonier_webhook', $config['driver'], $config['models'], $container);

        $container->setParameter('tmonier_webhook.enabled', $config['enabled']);
        $container->setParameter('tmonier_webhook.events', $config['events']);
        $container->setParameter('tmonier_webhook.retry_schedule', $config['retry_schedule']);
        $container->setParameter('tmonier_webhook.retry_jitter', $config['retry_jitter']);
        $container->setParameter('tmonier_webhook.timeout', $config['timeout']);
        $container->setParameter('tmonier_webhook.max_duration', $config['max_duration']);
        $container->setParameter('tmonier_webhook.response_body_max_bytes', $config['response_body_max_bytes']);
        $container->setParameter('tmonier_webhook.retention_days', $config['retention_days']);
        $container->setParameter('tmonier_webhook.allow_http_endpoints', $config['allow_http_endpoints']);
        $container->setParameter('tmonier_webhook.url_redaction_pattern', $config['url_redaction_pattern']);
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
        $config = self::config($this->processConfiguration(new Configuration(), $container->getExtensionConfig($this->getAlias())));
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

    /**
     * The processed configuration, as guaranteed by Configuration.
     *
     * @return array{
     *     enabled: bool,
     *     events: array<string, array{enabled: bool}>,
     *     retry_schedule: list<int>,
     *     retry_jitter: float,
     *     timeout: float,
     *     max_duration: float,
     *     response_body_max_bytes: int,
     *     retention_days: int|null,
     *     allow_http_endpoints: bool,
     *     url_redaction_pattern: string|null,
     *     allowed_private_networks: list<string>,
     *     driver: string,
     *     models: array<string, array{classes: array{model: class-string, interface: class-string, controller: class-string, factory: class-string, repository: class-string, form?: class-string}}>,
     * }
     */
    private static function config(array $config): array
    {
        /** @phpstan-ignore return.type (shape enforced by the configuration tree) */
        return $config;
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
