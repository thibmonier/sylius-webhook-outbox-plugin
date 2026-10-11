<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Twig;

use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\WebhookDeliveryController;
use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\WebhookEndpointController;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\EnvelopeFactory;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\EventTypeRegistryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class WebhookExtension extends AbstractExtension
{
    private const STATUS_COLORS = [
        WebhookDeliveryInterface::STATUS_PENDING => 'blue',
        WebhookDeliveryInterface::STATUS_FAILED => 'orange',
        WebhookDeliveryInterface::STATUS_DELIVERED => 'green',
        WebhookDeliveryInterface::STATUS_DEAD => 'red',
    ];

    public function __construct(private readonly EventTypeRegistryInterface $eventTypes)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('tmonier_webhook_status_color', static fn (string $status): string => self::STATUS_COLORS[$status] ?? 'secondary'),
            new TwigFunction('tmonier_webhook_event_type_label', $this->eventTypeLabel(...)),
            new TwigFunction('tmonier_webhook_envelope', static fn (OutboxEventInterface $event): string => json_encode(EnvelopeFactory::create($event), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)),
            new TwigFunction('tmonier_webhook_endpoint_token_id', WebhookEndpointController::csrfTokenId(...)),
            new TwigFunction('tmonier_webhook_delivery_token_id', WebhookDeliveryController::csrfTokenId(...)),
            new TwigFunction('tmonier_webhook_bulk_token_id', static fn (): string => WebhookDeliveryController::BULK_TOKEN_ID),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('tmonier_webhook_redact_url', static fn (?string $url): string => null === $url ? '' : UrlRedactor::redact($url)),
            new TwigFilter('tmonier_webhook_pretty_body', self::prettyBody(...)),
        ];
    }

    public function eventTypeLabel(string $type): string
    {
        return $this->eventTypes->has($type) ? $this->eventTypes->get($type)->getLabel() : $type;
    }

    /** Pretty-prints JSON bodies, leaves anything else untouched. */
    public static function prettyBody(?string $body): string
    {
        if (null === $body || '' === $body) {
            return '';
        }

        try {
            $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $body;
        }

        return is_array($decoded) ? (string) json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : $body;
    }
}
