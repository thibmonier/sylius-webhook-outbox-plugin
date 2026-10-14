<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Http;

/**
 * Static URL rules, checked by the admin form and again before each delivery. Private
 * addresses are blocked at connection time by NoPrivateNetworkHttpClient (DNS included).
 */
final readonly class EndpointUrlPolicy
{
    public function __construct(private bool $allowHttp = false)
    {
    }

    /** @return string|null translation key of the violation (validators domain), null when valid */
    public function violation(?string $url): ?string
    {
        if (null === $url || '' === trim($url) || strlen($url) > 2048) {
            return 'tmonier_webhook.endpoint.url.invalid';
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || '' === $parts['host']) {
            return 'tmonier_webhook.endpoint.url.invalid';
        }

        $scheme = strtolower($parts['scheme']);
        if ('https' !== $scheme && ('http' !== $scheme || !$this->allowHttp)) {
            return $this->allowHttp ? 'tmonier_webhook.endpoint.url.http_or_https' : 'tmonier_webhook.endpoint.url.https_only';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'tmonier_webhook.endpoint.url.no_credentials';
        }

        if (isset($parts['fragment'])) {
            return 'tmonier_webhook.endpoint.url.invalid';
        }

        return null;
    }
}
