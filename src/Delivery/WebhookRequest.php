<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

final readonly class WebhookRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $url,
        public string $body,
        public array $headers,
    ) {
    }
}
