<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;

/**
 * Retry now (same delivery, due immediately) and replay (new delivery of the same event, hence
 * the same webhook-id). The worker sends them: nothing is sent from the HTTP request.
 */
final readonly class WebhookDeliveryController
{
    public const BULK_TOKEN_ID = 'tmonier_webhook_bulk_replay';

    private const MAX_BULK = 500;

    /** @param class-string<WebhookDeliveryInterface> $deliveryClass */
    public function __construct(
        private WebhookDeliveryRepositoryInterface $repository,
        private EntityManagerInterface $entityManager,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
        private string $deliveryClass,
    ) {
    }

    public function retryAction(Request $request, int $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $delivery = $this->find($id);
        if (!$this->isCsrfTokenValid($request, self::csrfTokenId($id))) {
            return $this->toShow($request, $id, 'error', 'tmonier_webhook.invalid_csrf_token');
        }

        if (!$delivery->getEndpoint()->isEnabled()) {
            return $this->toShow($request, $id, 'error', 'tmonier_webhook.endpoint_disabled');
        }
        if (!$delivery->retryNow($this->clock->now())) {
            return $this->toShow($request, $id, 'error', 'tmonier_webhook.cannot_retry');
        }
        $this->entityManager->flush();

        return $this->toShow($request, $id, 'success', 'tmonier_webhook.retry_scheduled');
    }

    public function replayAction(Request $request, int $id): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $delivery = $this->find($id);
        if (!$this->isCsrfTokenValid($request, self::csrfTokenId($id))) {
            return $this->toShow($request, $id, 'error', 'tmonier_webhook.invalid_csrf_token');
        }

        if (!$delivery->getEndpoint()->isEnabled()) {
            return $this->toShow($request, $id, 'error', 'tmonier_webhook.endpoint_disabled');
        }
        $replay = $this->replay($delivery);
        if (!$replay instanceof \Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface) {
            return $this->toShow($request, $id, 'error', $delivery->getEvent()->isAnonymized() ? 'tmonier_webhook.cannot_replay_anonymized' : 'tmonier_webhook.cannot_replay');
        }
        $this->entityManager->flush();

        return $this->toShow($request, (int) $replay->getId(), 'success', 'tmonier_webhook.replay_scheduled');
    }

    public function bulkReplayAction(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $index = $this->urlGenerator->generate('tmonier_webhook_admin_webhook_delivery_index');
        if (!$this->isCsrfTokenValid($request, self::BULK_TOKEN_ID)) {
            $this->flash($request, 'error', 'tmonier_webhook.invalid_csrf_token');

            return new RedirectResponse($index);
        }

        $ids = $request->request->all('ids');
        $ids = array_slice(array_values(array_unique(array_filter(array_map(
            static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0,
            $ids,
        )))), 0, self::MAX_BULK);

        $replayed = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $delivery = $this->repository->find($id);
            if ($delivery instanceof WebhookDeliveryInterface && $this->replay($delivery) instanceof \Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface) {
                ++$replayed;
            } else {
                ++$skipped;
            }
        }
        $this->entityManager->flush();

        $this->flash($request, 0 === $replayed ? 'error' : 'success', 'tmonier_webhook.bulk_replay_done', ['%replayed%' => $replayed, '%skipped%' => $skipped]);

        return new RedirectResponse($this->localReferer($request) ?? $index);
    }

    public static function csrfTokenId(int|string|null $id): string
    {
        return 'tmonier_webhook_delivery_' . $id;
    }

    private function replay(WebhookDeliveryInterface $delivery): ?WebhookDeliveryInterface
    {
        if (!$delivery->canBeReplayed() || !$delivery->getEndpoint()->isEnabled()) {
            return null;
        }

        $class = $this->deliveryClass;
        $replay = new $class($delivery->getEvent(), $delivery->getEndpoint(), $this->clock->now(), $delivery->getId());
        $this->entityManager->persist($replay);

        return $replay;
    }

    private function find(int $id): WebhookDeliveryInterface
    {
        $delivery = $this->repository->find($id);
        if (!$delivery instanceof WebhookDeliveryInterface) {
            throw new NotFoundHttpException('Webhook delivery not found.');
        }

        return $delivery;
    }

    private function isCsrfTokenValid(Request $request, string $tokenId): bool
    {
        $token = $request->request->get('_csrf_token');

        return is_string($token) && $this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $token));
    }

    private function toShow(Request $request, int $id, string $type, string $message): RedirectResponse
    {
        $this->flash($request, $type, $message);

        return new RedirectResponse($this->urlGenerator->generate('tmonier_webhook_admin_webhook_delivery_show', ['id' => $id]));
    }

    /** @param array<string, int> $parameters */
    private function flash(Request $request, string $type, string $message, array $parameters = []): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, [] === $parameters ? $message : ['message' => $message, 'parameters' => $parameters]);
        }
    }

    /** Path of the Referer when it belongs to this application (filters of the grid kept). */
    private function localReferer(Request $request): ?string
    {
        $referer = $request->headers->get('referer');
        if (null === $referer) {
            return null;
        }
        $parts = parse_url($referer);
        if (false === $parts || (isset($parts['host']) && $parts['host'] !== $request->getHost()) || !isset($parts['path'])) {
            return null;
        }

        return $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
