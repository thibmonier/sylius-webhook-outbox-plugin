<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventListener;

use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin\SecretRevealer;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\SecretGenerator;

/**
 * Admin creation of an endpoint: the secret is generated before the insert, then the response
 * is the "copy your secret" page instead of the usual redirection, so that the secret never
 * goes through the session (flash) nor a URL.
 */
final readonly class EndpointSecretListener
{
    public function __construct(
        private SecretGenerator $secretGenerator,
        private SecretRevealer $revealer,
    ) {
    }

    public function onPreCreate(ResourceControllerEvent $event): void
    {
        $endpoint = $event->getSubject();
        if ($endpoint instanceof WebhookEndpointInterface && null === $endpoint->getSecret()) {
            $endpoint->setSecret($this->secretGenerator->generate());
        }
    }

    public function onPostCreate(ResourceControllerEvent $event): void
    {
        $endpoint = $event->getSubject();
        if ($endpoint instanceof WebhookEndpointInterface) {
            $event->setResponse($this->revealer->reveal($endpoint, true));
        }
    }
}
