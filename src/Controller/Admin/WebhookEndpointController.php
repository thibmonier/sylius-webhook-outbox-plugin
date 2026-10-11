<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\SecretGenerator;

/** Endpoint actions besides the CRUD: regenerate the secret, ping. */
final class WebhookEndpointController
{
    public function __construct(
        private readonly WebhookEndpointRepositoryInterface $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretGenerator $secretGenerator,
        private readonly SecretRevealer $revealer,
        private readonly OutboxRecorderInterface $recorder,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function regenerateSecretAction(Request $request, int $id): Response
    {
        $endpoint = $this->find($id);
        if (!$this->isCsrfTokenValid($request, $id)) {
            return $this->back($request, 'error', 'tmonier_webhook.invalid_csrf_token');
        }

        $endpoint->setSecret($this->secretGenerator->generate());
        $this->entityManager->flush();

        return $this->revealer->reveal($endpoint, false);
    }

    public function pingAction(Request $request, int $id): Response
    {
        $endpoint = $this->find($id);
        if (!$this->isCsrfTokenValid($request, $id)) {
            return $this->back($request, 'error', 'tmonier_webhook.invalid_csrf_token');
        }

        $this->recorder->record(new WebhookEvent(
            CoreEventTypeProvider::WEBHOOK_PING,
            ['endpoint_id' => $endpoint->getId(), 'endpoint_name' => $endpoint->getName(), 'message' => 'Ping from Sylius'],
            null,
            'webhook_endpoint',
            $endpoint->getId(),
        ), $endpoint);
        $this->entityManager->flush();

        $this->flash($request, 'success', 'tmonier_webhook.ping_queued');

        return new RedirectResponse($this->urlGenerator->generate('tmonier_webhook_admin_webhook_delivery_index', [
            'criteria' => ['endpoint' => (string) $endpoint->getId()],
        ]));
    }

    public static function csrfTokenId(int|string|null $id): string
    {
        return 'tmonier_webhook_endpoint_' . $id;
    }

    private function find(int $id): WebhookEndpointInterface
    {
        $endpoint = $this->repository->find($id);
        if (!$endpoint instanceof WebhookEndpointInterface) {
            throw new NotFoundHttpException('Webhook endpoint not found.');
        }

        return $endpoint;
    }

    private function isCsrfTokenValid(Request $request, int $id): bool
    {
        $token = $request->request->get('_csrf_token');

        return is_string($token) && $this->csrfTokenManager->isTokenValid(new CsrfToken(self::csrfTokenId($id), $token));
    }

    private function back(Request $request, string $type, string $message): RedirectResponse
    {
        $this->flash($request, $type, $message);

        return new RedirectResponse($this->urlGenerator->generate('tmonier_webhook_admin_webhook_endpoint_index'));
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
