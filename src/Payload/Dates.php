<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Payload;

/** Payload dates: ISO 8601 / RFC 3339 with the offset of the stored value. */
final class Dates
{
    public static function format(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DATE_ATOM);
    }
}
