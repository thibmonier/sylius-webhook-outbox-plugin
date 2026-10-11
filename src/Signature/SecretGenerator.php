<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Signature;

/** "whsec_" + base64 of 32 random bytes (Standard Webhooks: 24 to 64 bytes). */
final class SecretGenerator
{
    public function generate(): string
    {
        return WebhookSigner::SECRET_PREFIX . base64_encode(random_bytes(32));
    }
}
