<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckInterface;
use Tmonier\SyliusWebhookOutboxPlugin\DependencyInjection\TmonierSyliusWebhookOutboxExtension;
use Tmonier\SyliusWebhookOutboxPlugin\TmonierSyliusWebhookOutboxPlugin;

/**
 * A shop that installed the plugin and not the health plugin: the extension loads, no probe is registered, and no class of
 * the health plugin is needed. The interface check is injected, the health plugin being installed here for the tests.
 */
final class WithoutHealthPluginTest extends TestCase
{
    private const PROBES = ['tmonier_webhook.health.backlog', 'tmonier_webhook.health.dead_deliveries'];

    public function testTheExtensionLoadsWithoutAnyProbe(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        self::assertTrue($container->hasDefinition('tmonier_webhook.sender'), 'The services of the plugin are loaded.');
        foreach (self::PROBES as $probe) {
            self::assertFalse($container->hasDefinition($probe), $probe);
        }
    }

    public function testNoServiceOfTheHealthNamespaceIsRegisteredWithoutTheHealthPlugin(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => false);

        $classes = array_map(static fn ($definition): ?string => $definition->getClass(), $container->getDefinitions());
        self::assertSame([], array_values(array_filter($classes, static fn (?string $class): bool => null !== $class && str_contains($class, '\\Health\\'))));
    }

    public function testTheProbesAreAutoconfiguredWhenTheHealthPluginIsInstalled(): void
    {
        $container = $this->loaded(static fn (string $interface): bool => HealthCheckInterface::class === $interface);

        foreach (self::PROBES as $probe) {
            self::assertTrue($container->hasDefinition($probe), $probe);
            self::assertTrue($container->getDefinition($probe)->isAutoconfigured(), $probe);
        }
    }

    public function testTheBundleHandsTheCheckToItsExtension(): void
    {
        $asked = [];
        $bundle = new TmonierSyliusWebhookOutboxPlugin(static function (string $interface) use (&$asked): bool {
            $asked[] = $interface;

            return false;
        });
        $container = new ContainerBuilder();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $extension->load([], $container);

        self::assertSame([HealthCheckInterface::class], $asked);
    }

    /**
     * @param \Closure(string): bool $interfaceExists
     */
    private function loaded(\Closure $interfaceExists): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new TmonierSyliusWebhookOutboxExtension($interfaceExists))->load([], $container);

        return $container;
    }
}
