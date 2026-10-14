<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Outbox;

/**
 * An event to record in the outbox. "data" is the versioned DTO of the type (arrays and
 * scalars only; LazyValue for what the flush has not computed yet).
 */
final readonly class WebhookEvent
{
    /**
     * @param array<string, mixed> $data
     * @param string|null $resourceType short resource name of the subject (order, customer...)
     * @param int|string|LazyValue|null $customerId customer whose personal data the payload holds (erasure)
     */
    public function __construct(
        public string $type,
        public array $data,
        public ?string $channelCode = null,
        public ?string $resourceType = null,
        public int|string|LazyValue|null $resourceId = null,
        public int|string|LazyValue|null $customerId = null,
    ) {
    }
}
