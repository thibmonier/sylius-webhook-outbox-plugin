<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\HttpWebhookSender;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookRequest;

final class HttpWebhookSenderTest extends TestCase
{
    public function testSuccessKeepsATruncatedBody(): void
    {
        $client = new MockHttpClient(new MockResponse(str_repeat('a', 5000), ['http_code' => 202]));
        $attempt = (new HttpWebhookSender($client, new MockClock('2026-10-11 10:00:00'), 2048))->send($this->request());

        self::assertTrue($attempt->isSuccessful());
        self::assertSame(202, $attempt->statusCode);
        self::assertSame(2048, strlen((string) $attempt->responseBody));
        self::assertNull($attempt->error);
    }

    public function testSendsJsonWithTheGivenHeaders(): void
    {
        $seen = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = is_array($options['headers'] ?? null) ? $options['headers'] : [];

            return new MockResponse('');
        });
        (new HttpWebhookSender($client, new MockClock()))->send($this->request());

        self::assertContains('Content-Type: application/json', $seen);
        self::assertContains('webhook-id: msg_1', $seen);
    }

    public function testThrottlingWithRetryAfter(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 60']]));
        $attempt = (new HttpWebhookSender($client, new MockClock()))->send($this->request());

        self::assertFalse($attempt->isSuccessful());
        self::assertSame(429, $attempt->statusCode);
        self::assertSame(60, $attempt->retryAfterSeconds);
        self::assertNull($attempt->responseBody);
    }

    public function testTransportErrorsBecomeFailedAttemptsWithoutCredentials(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host for "https://user:pass@hooks.example.com/x?key=abc"']));
        $attempt = (new HttpWebhookSender($client, new MockClock()))->send(new WebhookRequest('https://user:pass@hooks.example.com/x?key=abc', '{}', []));

        self::assertNull($attempt->statusCode);
        self::assertNotNull($attempt->error);
        self::assertStringContainsString('hooks.example.com/x', $attempt->error);
        self::assertStringNotContainsString('pass', $attempt->error);
        self::assertStringNotContainsString('key=abc', $attempt->error);
    }

    public function testRedirectsAreNotFollowed(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://elsewhere.example.com/']]));
        $attempt = (new HttpWebhookSender($client->withOptions(['max_redirects' => 0]), new MockClock()))->send($this->request());

        self::assertSame(301, $attempt->statusCode);
        self::assertFalse($attempt->isSuccessful());
    }

    private function request(): WebhookRequest
    {
        return new WebhookRequest('https://hooks.example.com/sylius', '{"id":"msg_1"}', ['webhook-id' => 'msg_1']);
    }
}
