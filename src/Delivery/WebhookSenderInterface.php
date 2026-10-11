<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;

/**
 * Sends one signed request. The default backend is HttpWebhookSender (symfony/http-client);
 * a symfony/webhook or Svix backend can replace it.
 *
 * Must not throw: network errors are returned as a failed attempt.
 */
interface WebhookSenderInterface
{
    public function send(WebhookRequest $request): DeliveryAttempt;
}
