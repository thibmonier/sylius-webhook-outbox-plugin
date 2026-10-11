<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpClient\HttpClient;
use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\SecretRevealer;
use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\WebhookDeliveryController;
use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\WebhookEndpointController;
use Tmonier\SyliusWebhookOutboxPlugin\EventListener\EndpointAvailabilityListener;
use Tmonier\SyliusWebhookOutboxPlugin\EventListener\EndpointSecretListener;
use Tmonier\SyliusWebhookOutboxPlugin\Form\Type\WebhookEndpointType;
use Tmonier\SyliusWebhookOutboxPlugin\Menu\AdminMenuListener;
use Tmonier\SyliusWebhookOutboxPlugin\Twig\WebhookExtension;
use Tmonier\SyliusWebhookOutboxPlugin\Validator\EndpointUrlValidator;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector\CustomerEventDetector;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector\OrderEventDetector;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector\StockChangedDetector;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\EntityGuard;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\OutboxCaptureListener;
use Tmonier\SyliusWebhookOutboxPlugin\Command\AnonymizeCustomerWebhooksCommand;
use Tmonier\SyliusWebhookOutboxPlugin\Command\DispatchWebhooksCommand;
use Tmonier\SyliusWebhookOutboxPlugin\Command\PurgeWebhooksCommand;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\DeliveryClaimer;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\HttpWebhookSender;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\RetryPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookSenderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistry;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointHttpClientFactory;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\CustomerWebhookDataAnonymizer;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\PayloadRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorder;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\AdminUrlGenerator;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\CustomerPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\OrderPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\ProductVariantPayloadNormalizer;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\SecretGenerator;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->alias(WebhookEndpointRepositoryInterface::class, 'tmonier_webhook.repository.webhook_endpoint');
    $services->alias(OutboxEventRepositoryInterface::class, 'tmonier_webhook.repository.outbox_event');
    $services->alias(WebhookDeliveryRepositoryInterface::class, 'tmonier_webhook.repository.webhook_delivery');

    // Event types
    $services
        ->set('tmonier_webhook.event_type_provider.core', CoreEventTypeProvider::class)
        ->tag('tmonier_webhook.event_type_provider')
    ;
    $services
        ->set('tmonier_webhook.event_type_registry', EventTypeRegistry::class)
        ->args([
            tagged_iterator('tmonier_webhook.event_type_provider'),
            param('tmonier_webhook.events'),
        ])
    ;
    $services->alias(EventTypeRegistryInterface::class, 'tmonier_webhook.event_type_registry');

    // Payloads
    $services
        ->set('tmonier_webhook.payload.admin_url_generator', AdminUrlGenerator::class)
        ->args([service('router')])
    ;
    $services
        ->set('tmonier_webhook.payload.customer', CustomerPayloadNormalizer::class)
        ->args([service('tmonier_webhook.payload.admin_url_generator')])
    ;
    $services
        ->set('tmonier_webhook.payload.order', OrderPayloadNormalizer::class)
        ->args([
            service('tmonier_webhook.payload.admin_url_generator'),
            service('tmonier_webhook.payload.customer'),
        ])
    ;
    $services
        ->set('tmonier_webhook.payload.product_variant', ProductVariantPayloadNormalizer::class)
        ->args([service('tmonier_webhook.payload.admin_url_generator')])
    ;

    // Capture
    $services
        ->set('tmonier_webhook.retry_policy', RetryPolicy::class)
        ->args([
            param('tmonier_webhook.retry_schedule'),
            param('tmonier_webhook.retry_jitter'),
        ])
    ;
    $services
        ->set('tmonier_webhook.outbox_recorder', OutboxRecorder::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('tmonier_webhook.repository.webhook_endpoint'),
            service('tmonier_webhook.event_type_registry'),
            service('tmonier_webhook.retry_policy'),
            service('clock'),
            param('tmonier_webhook.model.outbox_event.class'),
            param('tmonier_webhook.model.webhook_delivery.class'),
            param('tmonier_webhook.enabled'),
        ])
    ;
    $services->alias(OutboxRecorderInterface::class, 'tmonier_webhook.outbox_recorder')->public();

    $services
        ->set('tmonier_webhook.capture.entity_guard', EntityGuard::class)
        ->args([service('logger')])
        ->tag('monolog.logger', ['channel' => 'tmonier_webhook'])
    ;
    $services
        ->set('tmonier_webhook.change_detector.order', OrderEventDetector::class)
        ->args([service('tmonier_webhook.payload.order'), service('tmonier_webhook.capture.entity_guard')])
        ->tag('tmonier_webhook.change_detector', ['priority' => 300])
    ;
    $services
        ->set('tmonier_webhook.change_detector.customer', CustomerEventDetector::class)
        ->args([service('tmonier_webhook.payload.customer'), service('tmonier_webhook.capture.entity_guard')])
        ->tag('tmonier_webhook.change_detector', ['priority' => 200])
    ;
    $services
        ->set('tmonier_webhook.change_detector.stock', StockChangedDetector::class)
        ->args([service('tmonier_webhook.payload.product_variant'), service('tmonier_webhook.capture.entity_guard')])
        ->tag('tmonier_webhook.change_detector', ['priority' => 100])
    ;
    $services
        ->set('tmonier_webhook.capture_listener', OutboxCaptureListener::class)
        ->args([
            tagged_iterator('tmonier_webhook.change_detector'),
            service('tmonier_webhook.outbox_recorder'),
            service('logger'),
            param('tmonier_webhook.enabled'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('doctrine.event_listener', ['event' => 'postPersist'])
        ->tag('monolog.logger', ['channel' => 'tmonier_webhook'])
    ;

    // Delivery
    $services
        ->set('tmonier_webhook.signer', WebhookSigner::class)
    ;
    $services->set('tmonier_webhook.secret_generator', SecretGenerator::class);
    $services
        ->set('tmonier_webhook.url_redactor', UrlRedactor::class)
        ->args([param('tmonier_webhook.url_redaction_pattern')])
        ->public()
    ;
    $services
        ->set('tmonier_webhook.url_policy', EndpointUrlPolicy::class)
        ->args([param('tmonier_webhook.allow_http_endpoints')])
    ;

    // Raw transport, replaced by a MockHttpClient in the test application.
    $services
        ->set('tmonier_webhook.http_client.transport')
        ->class('Symfony\Contracts\HttpClient\HttpClientInterface')
        ->factory([HttpClient::class, 'create'])
    ;
    $services
        ->set('tmonier_webhook.http_client')
        ->class('Symfony\Contracts\HttpClient\HttpClientInterface')
        ->factory([EndpointHttpClientFactory::class, 'create'])
        ->args([
            service('tmonier_webhook.http_client.transport'),
            param('tmonier_webhook.allowed_private_networks'),
            param('tmonier_webhook.timeout'),
            param('tmonier_webhook.max_duration'),
        ])
    ;
    $services
        ->set('tmonier_webhook.sender', HttpWebhookSender::class)
        ->args([
            service('tmonier_webhook.http_client'),
            service('clock'),
            param('tmonier_webhook.response_body_max_bytes'),
            service('tmonier_webhook.url_redactor'),
        ])
    ;
    $services->alias(WebhookSenderInterface::class, 'tmonier_webhook.sender');

    $services
        ->set('tmonier_webhook.delivery_claimer', DeliveryClaimer::class)
        ->args([
            service('doctrine'),
            service('clock'),
            param('tmonier_webhook.model.webhook_delivery.class'),
            param('tmonier_webhook.model.webhook_endpoint.class'),
            param('tmonier_webhook.claim_lease'),
        ])
    ;
    $services
        ->set('tmonier_webhook.dispatcher', WebhookDispatcher::class)
        ->args([
            service('doctrine'),
            service('tmonier_webhook.delivery_claimer'),
            service('tmonier_webhook.sender'),
            service('tmonier_webhook.signer'),
            service('tmonier_webhook.retry_policy'),
            service('tmonier_webhook.url_policy'),
            service('tmonier_webhook.url_redactor'),
            service('clock'),
            service('logger'),
            param('tmonier_webhook.model.webhook_delivery.class'),
        ])
        ->tag('monolog.logger', ['channel' => 'tmonier_webhook'])
    ;

    $services
        ->set('tmonier_webhook.listener.endpoint_availability', EndpointAvailabilityListener::class)
        ->args([
            service('clock'),
            param('tmonier_webhook.model.webhook_delivery.class'),
        ])
        ->tag('doctrine.event_listener', ['event' => 'postUpdate'])
    ;

    // Maintenance
    $services->set('tmonier_webhook.payload_redactor', PayloadRedactor::class);
    $services
        ->set('tmonier_webhook.customer_anonymizer', CustomerWebhookDataAnonymizer::class)
        ->args([
            service('tmonier_webhook.repository.outbox_event'),
            service('tmonier_webhook.repository.webhook_delivery'),
            service('doctrine.orm.entity_manager'),
            service('tmonier_webhook.payload_redactor'),
            service('clock'),
        ])
    ;

    // Admin
    $services
        ->set('tmonier_webhook.form.type.webhook_endpoint', WebhookEndpointType::class)
        ->args([
            param('tmonier_webhook.model.webhook_endpoint.class'),
            ['sylius'],
            service('tmonier_webhook.event_type_registry'),
            service('sylius.repository.channel'),
        ])
        ->tag('form.type')
    ;
    $services
        ->set('tmonier_webhook.validator.endpoint_url', EndpointUrlValidator::class)
        ->args([service('tmonier_webhook.url_policy')])
        ->tag('validator.constraint_validator', ['alias' => 'tmonier_webhook_endpoint_url'])
    ;
    $services
        ->set('tmonier_webhook.secret_revealer', SecretRevealer::class)
        ->args([service('twig')])
    ;
    $services
        ->set('tmonier_webhook.listener.endpoint_secret', EndpointSecretListener::class)
        ->args([
            service('tmonier_webhook.secret_generator'),
            service('tmonier_webhook.secret_revealer'),
        ])
        ->tag('kernel.event_listener', ['event' => 'tmonier_webhook.webhook_endpoint.pre_create', 'method' => 'onPreCreate'])
        ->tag('kernel.event_listener', ['event' => 'tmonier_webhook.webhook_endpoint.post_create', 'method' => 'onPostCreate'])
    ;
    $services
        ->set('tmonier_webhook.controller.admin.webhook_endpoint', WebhookEndpointController::class)
        ->args([
            service('tmonier_webhook.repository.webhook_endpoint'),
            service('doctrine.orm.entity_manager'),
            service('tmonier_webhook.secret_generator'),
            service('tmonier_webhook.secret_revealer'),
            service('tmonier_webhook.outbox_recorder'),
            service('security.csrf.token_manager'),
            service('router'),
        ])
        ->public()
    ;
    $services
        ->set('tmonier_webhook.controller.admin.webhook_delivery', WebhookDeliveryController::class)
        ->args([
            service('tmonier_webhook.repository.webhook_delivery'),
            service('doctrine.orm.entity_manager'),
            service('security.csrf.token_manager'),
            service('router'),
            service('clock'),
            param('tmonier_webhook.model.webhook_delivery.class'),
        ])
        ->public()
    ;
    $services
        ->set('tmonier_webhook.admin_menu_listener', AdminMenuListener::class)
        ->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main'])
    ;
    $services
        ->set('tmonier_webhook.twig.extension', WebhookExtension::class)
        ->args([
            service('tmonier_webhook.event_type_registry'),
            service('tmonier_webhook.url_redactor'),
        ])
        ->tag('twig.extension')
    ;

    // Commands
    $services
        ->set('tmonier_webhook.command.dispatch', DispatchWebhooksCommand::class)
        ->args([
            service('tmonier_webhook.dispatcher'),
            service('logger'),
            param('tmonier_webhook.max_duration'),
        ])
        ->tag('console.command')
        ->tag('monolog.logger', ['channel' => 'tmonier_webhook'])
    ;
    $services
        ->set('tmonier_webhook.command.purge', PurgeWebhooksCommand::class)
        ->args([
            service('tmonier_webhook.repository.outbox_event'),
            service('clock'),
            param('tmonier_webhook.retention_days'),
        ])
        ->tag('console.command')
    ;
    $services
        ->set('tmonier_webhook.command.anonymize_customer', AnonymizeCustomerWebhooksCommand::class)
        ->args([
            service('tmonier_webhook.customer_anonymizer'),
            service('sylius.repository.customer'),
        ])
        ->tag('console.command')
    ;
};
