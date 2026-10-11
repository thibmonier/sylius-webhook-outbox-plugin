<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointHttpClientFactory;

final class EndpointHttpClientFactoryTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'rfc1918' => ['https://192.168.1.10/hook'];
        yield 'ipv6 loopback' => ['https://[::1]/hook'];
    }

    /** @dataProvider blockedUrls */
    public function testPrivateAddressesAreBlocked(string $url): void
    {
        $client = EndpointHttpClientFactory::create(new MockHttpClient(new MockResponse('ok')));

        $this->expectException(TransportException::class);
        $client->request('POST', $url)->getStatusCode();
    }

    public function testPublicAddressesGoThrough(): void
    {
        $client = EndpointHttpClientFactory::create(new MockHttpClient(new MockResponse('ok')));

        self::assertSame(200, $client->request('POST', 'https://93.184.215.14/hook')->getStatusCode());
    }

    public function testAllowedPrivateNetworkOnly(): void
    {
        $client = EndpointHttpClientFactory::create(new MockHttpClient([new MockResponse('ok'), new MockResponse('ok')]), ['10.0.12.0/24']);

        self::assertSame(200, $client->request('POST', 'https://10.0.12.5/erp')->getStatusCode());

        $this->expectException(TransportException::class);
        $client->request('POST', 'https://10.0.13.5/erp')->getStatusCode();
    }
}
