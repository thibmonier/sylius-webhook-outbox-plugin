<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Http;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * MockHttpClient callback of the test application: answers the queued responses (200 when
 * the queue is empty) and keeps the requests for assertions. No network.
 */
final class WebhookReceiverStub implements ResetInterface
{
    /** @var list<MockResponse|\Closure(string, string, array<string, mixed>): ResponseInterface> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    private array $requests = [];

    public function queue(MockResponse|\Closure ...$responses): void
    {
        array_push($this->queue, ...$responses);
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $headers = [];
        $normalized = $options['normalized_headers'] ?? [];
        if (is_array($normalized)) {
            foreach ($normalized as $name => $lines) {
                $line = is_array($lines) ? (string) ($lines[0] ?? '') : '';
                $headers[(string) $name] = trim(substr($line, strlen((string) $name) + 1));
            }
        }
        $body = $options['body'] ?? '';
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];

        $response = array_shift($this->queue) ?? new MockResponse('{"ok":true}', ['http_code' => 200]);

        return $response instanceof \Closure ? $response($method, $url, $options) : $response;
    }

    public function reset(): void
    {
        $this->queue = [];
        $this->requests = [];
    }
}
