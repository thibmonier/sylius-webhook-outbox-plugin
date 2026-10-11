<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

final class DeliveryClaim
{
    public function __construct(
        public readonly int $deliveryId,
        public readonly string $token,
    ) {
    }
}
