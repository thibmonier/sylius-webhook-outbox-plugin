<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Twig\Environment;

/**
 * The only page that ever shows an endpoint secret: answered directly to the POST that created
 * or regenerated it, never cached, never stored in the session.
 */
final readonly class SecretRevealer
{
    public function __construct(private Environment $twig)
    {
    }

    public function reveal(WebhookEndpointInterface $endpoint, bool $created): Response
    {
        $response = new Response($this->twig->render('@TmonierSyliusWebhookOutboxPlugin/admin/webhook_endpoint/secret.html.twig', [
            'resource' => $endpoint,
            'secret' => $endpoint->getSecret(),
            'created' => $created,
        ]));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
