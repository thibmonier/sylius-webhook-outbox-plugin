<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

/**
 * Request body of every delivery: {id, type, version, timestamp, channel, data}.
 * "id" equals the webhook-id header.
 */
final class EnvelopeFactory
{
    /** @return array{id: string, type: string, version: int, timestamp: string, channel: string|null, data: array<string, mixed>} */
    public static function create(OutboxEventInterface $event): array
    {
        return [
            'id' => $event->getId(),
            'type' => $event->getType(),
            'version' => $event->getVersion(),
            'timestamp' => $event->getOccurredAt()->format('Y-m-d\TH:i:s\Z'),
            'channel' => $event->getChannelCode(),
            'data' => $event->getPayload(),
        ];
    }

    public static function encode(OutboxEventInterface $event): string
    {
        return json_encode(self::create($event), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR);
    }
}
