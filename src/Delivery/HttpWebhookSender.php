<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;

/**
 * Default sender. The HTTP client is the plugin's dedicated client (SSRF guard, no redirect,
 * timeouts), see EndpointHttpClientFactory.
 */
final readonly class HttpWebhookSender implements WebhookSenderInterface
{
    public const USER_AGENT = 'Sylius-Webhook-Outbox/1.0 (+https://github.com/thibmonier/sylius-webhook-outbox-plugin)';

    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private int $responseBodyMaxBytes = 2048,
        private UrlRedactor $urlRedactor = new UrlRedactor(),
    ) {
    }

    public function send(WebhookRequest $request): DeliveryAttempt
    {
        $at = $this->clock->now();
        $start = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $request->url, [
                'headers' => ['Content-Type' => 'application/json', 'User-Agent' => self::USER_AGENT] + $request->headers,
                'body' => $request->body,
            ]);
            $statusCode = $response->getStatusCode();
            $retryAfter = RetryPolicy::parseRetryAfter($response->getHeaders(false)['retry-after'][0] ?? null, $at);
            $body = $this->readBody($response);
        } catch (ExceptionInterface $exception) {
            return new DeliveryAttempt($at, $this->elapsedMs($start), null, null, $this->urlRedactor->redactMessage($exception->getMessage(), $request->url));
        }

        return new DeliveryAttempt($at, $this->elapsedMs($start), $statusCode, $body, null, $retryAfter);
    }

    /** First bytes of the response body, the rest is not downloaded. */
    private function readBody(ResponseInterface $response): ?string
    {
        if ($this->responseBodyMaxBytes <= 0) {
            $response->cancel();

            return null;
        }

        $body = '';

        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) >= $this->responseBodyMaxBytes) {
                    $response->cancel();

                    break;
                }
            }
        } catch (ExceptionInterface) {
            // The status code is known: a body cut by a timeout does not change the outcome.
        }

        $body = substr($body, 0, $this->responseBodyMaxBytes);
        if ('' === $body) {
            return null;
        }

        // Keep valid UTF-8 for the JSON history and the admin panel.
        return mb_convert_encoding($body, 'UTF-8', 'UTF-8');
    }

    private function elapsedMs(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
