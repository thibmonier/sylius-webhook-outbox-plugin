<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

/**
 * Claims, signs, sends and records one delivery at a time. Logs never contain the secret, the
 * payload or the full URL.
 */
final class WebhookDispatcher
{
    /** @param class-string<WebhookDeliveryInterface> $deliveryClass */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DeliveryClaimer $claimer,
        private readonly WebhookSenderInterface $sender,
        private readonly WebhookSigner $signer,
        private readonly RetryPolicy $retryPolicy,
        private readonly EndpointUrlPolicy $urlPolicy,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $deliveryClass,
    ) {
    }

    /** @return WebhookDeliveryInterface|null the processed delivery, null when nothing is due */
    public function dispatchNext(): ?WebhookDeliveryInterface
    {
        $claim = $this->claimer->claimNext();
        if (null === $claim) {
            return null;
        }

        $delivery = $this->entityManager->find($this->deliveryClass, $claim->deliveryId);
        if (!$delivery instanceof WebhookDeliveryInterface) {
            return null;
        }
        $this->entityManager->refresh($delivery);

        $attempt = $this->attempt($delivery);

        $recorded = $this->entityManager->wrapInTransaction(function () use ($claim, $delivery, $attempt): bool {
            if (!$this->claimer->holds($claim)) {
                // The lease expired and another worker took the delivery over: its result wins.
                return false;
            }
            $this->apply($delivery, $attempt);
            $this->entityManager->flush();

            return true;
        });

        if (!$recorded) {
            $this->logger->warning('Webhook delivery {delivery}: lease lost, attempt result dropped.', ['delivery' => $claim->deliveryId]);
            $this->entityManager->clear();
        }

        return $delivery;
    }

    /** Sends without claim (tests, synchronous tools). The caller flushes. */
    public function deliver(WebhookDeliveryInterface $delivery): DeliveryAttempt
    {
        $attempt = $this->attempt($delivery);
        $this->apply($delivery, $attempt);

        return $attempt;
    }

    private function attempt(WebhookDeliveryInterface $delivery): DeliveryAttempt
    {
        $endpoint = $delivery->getEndpoint();
        $event = $delivery->getEvent();
        $now = $this->clock->now();
        $url = (string) $endpoint->getUrl();

        $violation = $this->urlPolicy->violation($url);
        $secret = $endpoint->getSecret();
        if (null !== $violation || null === $secret) {
            return new DeliveryAttempt($now, 0, null, null, null !== $violation ? 'Endpoint URL rejected: ' . $violation : 'Endpoint without secret.', null, true);
        }

        try {
            $body = EnvelopeFactory::encode($event);
            $headers = $this->signer->headers($event->getId(), $now->getTimestamp(), $body, $secret);
        } catch (\Throwable $exception) {
            return new DeliveryAttempt($now, 0, null, null, 'Cannot build the request: ' . $exception::class, null, true);
        }

        return $this->sender->send(new WebhookRequest($url, $body, $headers));
    }

    private function apply(WebhookDeliveryInterface $delivery, DeliveryAttempt $attempt): void
    {
        $delivery->recordAttempt($attempt);
        $now = $this->clock->now();
        $context = [
            'delivery' => $delivery->getId(),
            'event_type' => $delivery->getEvent()->getType(),
            'webhook_id' => $delivery->getEvent()->getId(),
            'endpoint' => $delivery->getEndpoint()->getId(),
            'url' => UrlRedactor::redact((string) $delivery->getEndpoint()->getUrl()),
            'status_code' => $attempt->statusCode,
            'attempt' => $delivery->getAttempts(),
        ];

        if ($attempt->isSuccessful()) {
            $delivery->markDelivered($now);
            $this->logger->info('Webhook {event_type} {webhook_id} delivered to endpoint {endpoint} ({status_code}).', $context);

            return;
        }

        if (410 === $attempt->statusCode) {
            $delivery->markDead();
            $delivery->getEndpoint()->disable('410 Gone', $now);
            $this->logger->warning('Webhook endpoint {endpoint} ({url}) answered 410 Gone: endpoint disabled.', $context);

            return;
        }

        $delay = $attempt->permanentFailure ? null : $this->retryPolicy->nextDelay($delivery->getAttempts(), $attempt);

        if (null === $delay) {
            $delivery->markDead();
            $this->logger->error('Webhook {event_type} {webhook_id} to endpoint {endpoint} is dead after {attempt} attempt(s): {error}', $context + ['error' => $attempt->error ?? 'HTTP ' . $attempt->statusCode]);

            return;
        }

        $delivery->scheduleRetry($now->modify(sprintf('+%d seconds', $delay)));
        $this->logger->notice('Webhook {event_type} {webhook_id} to endpoint {endpoint} failed ({error}), retry in {delay}s.', $context + ['error' => $attempt->error ?? 'HTTP ' . $attempt->statusCode, 'delay' => $delay]);
    }
}
