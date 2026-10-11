<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\DependencyInjection;

use Sylius\Bundle\ResourceBundle\Controller\ResourceController;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Sylius\Resource\Factory\Factory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDelivery;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Form\Type\WebhookEndpointType;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepository;

final class Configuration implements ConfigurationInterface
{
    /** Standard Webhooks schedule: immediately, 5s, 5m, 30m, 2h, 5h, 10h, 14h, 20h, 24h. */
    public const DEFAULT_RETRY_SCHEDULE = [0, 5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tmonier_sylius_webhook_outbox');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')
                    ->info('Capture events. When false, nothing is written to the outbox; the worker still delivers what is already there.')
                    ->defaultTrue()
                ->end()
                ->arrayNode('events')
                    ->info('Per event type switches, e.g. {product_variant.stock_changed: {enabled: false}}. Unlisted types are enabled.')
                    ->useAttributeAsKey('type')
                    ->arrayPrototype()
                        ->canBeDisabled()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('retry_schedule')
                    ->info('Delays in seconds before each attempt: the first value applies to the first attempt, the list length is the number of attempts.')
                    ->integerPrototype()->min(0)->end()
                    ->requiresAtLeastOneElement()
                    ->defaultValue(self::DEFAULT_RETRY_SCHEDULE)
                ->end()
                ->floatNode('retry_jitter')
                    ->info('Random extra delay added to each retry, as a fraction of the delay (0.1 = up to 10%).')
                    ->min(0)
                    ->max(1)
                    ->defaultValue(0.1)
                ->end()
                ->floatNode('timeout')
                    ->info('Idle timeout of a delivery request, in seconds.')
                    ->min(1)
                    ->defaultValue(15)
                ->end()
                ->floatNode('max_duration')
                    ->info('Maximum total duration of a delivery request, in seconds.')
                    ->min(1)
                    ->defaultValue(30)
                ->end()
                ->integerNode('response_body_max_bytes')
                    ->info('Bytes of the endpoint response body kept for the admin panel.')
                    ->min(0)
                    ->defaultValue(2048)
                ->end()
                ->scalarNode('retention_days')
                    ->info('Default age limit of tmonier:webhook:purge, in days. Null keeps events forever.')
                    ->defaultValue(30)
                    ->validate()
                        ->ifTrue(static fn (mixed $days): bool => null !== $days && (!is_int($days) || $days < 1))
                        ->thenInvalid('retention_days must be a positive number of days or null, %s given.')
                    ->end()
                ->end()
                ->booleanNode('allow_http_endpoints')
                    ->info('Accept plain http:// endpoint URLs. Development only: payloads and signatures travel in clear text.')
                    ->defaultFalse()
                ->end()
                ->scalarNode('url_redaction_pattern')
                    ->info('Regular expression of the URL path segments hidden in logs, errors and the admin (tokens of Slack-like hook URLs). Null shows every segment. Query strings are always hidden.')
                    ->defaultValue(UrlRedactor::DEFAULT_SECRET_SEGMENT_PATTERN)
                    ->validate()
                        ->ifTrue(static fn (mixed $pattern): bool => null !== $pattern && (!is_string($pattern) || false === @preg_match($pattern, '')))
                        ->thenInvalid('url_redaction_pattern must be a valid regular expression or null, %s given.')
                    ->end()
                ->end()
                ->arrayNode('allowed_private_networks')
                    ->info('CIDR ranges that endpoints may reach although they are private (e.g. an ERP on 10.0.12.0/24). Everything else private stays blocked.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $cidr): bool => !is_string($cidr) || !self::isCidr($cidr))
                            ->thenInvalid('%s is not a valid IP address or CIDR range.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->scalarNode('driver')->defaultValue(SyliusResourceBundle::DRIVER_DOCTRINE_ORM)->end()
            ->end()
        ;

        $this->addModelsSection($rootNode);

        return $treeBuilder;
    }

    public static function isCidr(string $cidr): bool
    {
        [$address, $bits] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        if (false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return false;
        }
        if (null === $bits) {
            return true;
        }
        $max = str_contains($address, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max && IpUtils::checkIp($address, $cidr);
    }

    private function addModelsSection(ArrayNodeDefinition $node): void
    {
        $models = [
            'webhook_endpoint' => [WebhookEndpoint::class, WebhookEndpointInterface::class, WebhookEndpointRepository::class, WebhookEndpointType::class],
            'outbox_event' => [OutboxEvent::class, OutboxEventInterface::class, OutboxEventRepository::class, null],
            'webhook_delivery' => [WebhookDelivery::class, WebhookDeliveryInterface::class, WebhookDeliveryRepository::class, null],
        ];

        $children = $node->children()->arrayNode('models')->addDefaultsIfNotSet()->children();
        foreach ($models as $name => [$model, $interface, $repository, $form]) {
            $classes = $children
                ->arrayNode($name)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('classes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('model')->defaultValue($model)->cannotBeEmpty()->end()
                                ->scalarNode('interface')->defaultValue($interface)->cannotBeEmpty()->end()
                                ->scalarNode('controller')->defaultValue(ResourceController::class)->cannotBeEmpty()->end()
                                ->scalarNode('factory')->defaultValue(Factory::class)->end()
                                ->scalarNode('repository')->defaultValue($repository)->cannotBeEmpty()->end()
            ;
            if (null !== $form) {
                $classes->scalarNode('form')->defaultValue($form)->cannotBeEmpty()->end();
            }
            $classes->end()->end()->end()->end();
        }
    }
}
