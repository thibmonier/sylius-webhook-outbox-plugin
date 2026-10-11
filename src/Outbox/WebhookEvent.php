<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

/**
 * An event to record in the outbox. "data" is the versioned DTO of the type (arrays and
 * scalars only; LazyValue for what the flush has not computed yet).
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     * @param string|null $resourceType short resource name of the subject (order, customer...)
     * @param int|string|LazyValue|null $customerId customer whose personal data the payload holds (erasure)
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly ?string $channelCode = null,
        public readonly ?string $resourceType = null,
        public readonly int|string|LazyValue|null $resourceId = null,
        public readonly int|string|LazyValue|null $customerId = null,
    ) {
    }
}
