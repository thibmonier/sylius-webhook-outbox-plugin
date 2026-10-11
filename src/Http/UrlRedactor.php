<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Http;

/**
 * Endpoint URLs may carry credentials (user info, tokens in the query string): error messages
 * and logs only show scheme, host, port and path.
 */
final class UrlRedactor
{
    public static function redact(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            return '[invalid url]';
        }

        return sprintf(
            '%s://%s%s%s%s',
            $parts['scheme'] ?? 'https',
            $parts['host'],
            isset($parts['port']) ? ':' . $parts['port'] : '',
            $parts['path'] ?? '',
            isset($parts['query']) ? '?[redacted]' : '',
        );
    }

    public static function redactMessage(string $message, string $url): string
    {
        $redacted = self::redact($url);
        $message = str_replace([$url, rawurldecode($url)], $redacted, $message);

        // Messages of the HTTP client sometimes quote the URL without its user info.
        $parts = parse_url($url);
        if (false !== $parts && isset($parts['query'])) {
            $message = str_replace('?' . $parts['query'], '?[redacted]', $message);
        }
        if (false !== $parts && isset($parts['user'])) {
            $message = str_replace($parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@', '', $message);
        }

        return mb_substr($message, 0, 1000);
    }
}
