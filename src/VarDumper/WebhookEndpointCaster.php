<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\VarDumper;

use Symfony\Component\VarDumper\Cloner\Stub;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;

/**
 * VarDumper ignores __debugInfo(): dump(), the profiler and the debug toolbar would show the
 * signing secret and the raw URL. Registered for every cloner by the bundle.
 */
final class WebhookEndpointCaster
{
    /**
     * @param array<array-key, mixed> $properties
     *
     * @return array<array-key, mixed>
     */
    public static function cast(WebhookEndpointInterface $endpoint, array $properties, Stub $stub, bool $isNested, int $filter = 0): array
    {
        foreach ($properties as $key => $value) {
            $name = is_string($key) ? substr($key, (int) strrpos($key, "\0") + (str_contains($key, "\0") ? 1 : 0)) : '';
            if ('secret' === $name && null !== $value) {
                $properties[$key] = '[redacted]';
            } elseif ('url' === $name && is_string($value)) {
                $properties[$key] = UrlRedactor::default()->redact($value);
            }
        }

        return $properties;
    }
}
