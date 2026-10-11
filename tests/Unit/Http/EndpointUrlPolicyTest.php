<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Http;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;

final class EndpointUrlPolicyTest extends TestCase
{
    public function testHttpsOnlyByDefault(): void
    {
        $policy = new EndpointUrlPolicy();

        self::assertNull($policy->violation('https://erp.example.com/webhooks/sylius?tenant=3'));
        self::assertSame('tmonier_webhook.endpoint.url.https_only', $policy->violation('http://erp.example.com/'));
        self::assertSame('tmonier_webhook.endpoint.url.https_only', $policy->violation('ftp://erp.example.com/'));
        self::assertSame('tmonier_webhook.endpoint.url.no_credentials', $policy->violation('https://user:pass@erp.example.com/'));
        self::assertSame('tmonier_webhook.endpoint.url.invalid', $policy->violation('not a url'));
        self::assertSame('tmonier_webhook.endpoint.url.invalid', $policy->violation(''));
        self::assertSame('tmonier_webhook.endpoint.url.invalid', $policy->violation(null));
        self::assertSame('tmonier_webhook.endpoint.url.invalid', $policy->violation('https://erp.example.com/#frag'));
    }

    public function testHttpAllowedForDevelopment(): void
    {
        self::assertNull((new EndpointUrlPolicy(true))->violation('http://localhost:8000/hook'));
        self::assertSame('tmonier_webhook.endpoint.url.http_or_https', (new EndpointUrlPolicy(true))->violation('ftp://x.example.com/'));
    }

    public function testRedactor(): void
    {
        self::assertSame('https://erp.example.com:8443/hook?[redacted]', UrlRedactor::redact('https://u:p@erp.example.com:8443/hook?token=abc'));
        self::assertSame('[invalid url]', UrlRedactor::redact('nope'));
        self::assertSame(
            'Timeout for "https://erp.example.com/hook?[redacted]".',
            UrlRedactor::redactMessage('Timeout for "https://erp.example.com/hook?token=abc".', 'https://erp.example.com/hook?token=abc'),
        );
    }
}
