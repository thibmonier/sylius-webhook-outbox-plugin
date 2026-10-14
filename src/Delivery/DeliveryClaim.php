<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

final readonly class DeliveryClaim
{
    public function __construct(
        public int $deliveryId,
        public string $token,
    ) {
    }
}
