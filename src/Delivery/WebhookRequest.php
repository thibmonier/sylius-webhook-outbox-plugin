<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

final class WebhookRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly string $url,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }
}
