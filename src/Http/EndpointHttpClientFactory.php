<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Http;

use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The dedicated client of the deliveries: private networks blocked (resolved addresses
 * included, CVE-2024-50342), no redirect, idle timeout and maximum duration.
 *
 * Proxies from the environment (HTTP_PROXY, HTTPS_PROXY, ALL_PROXY) are ignored: a proxy
 * resolves the host itself, which would bypass the private network check.
 */
final class EndpointHttpClientFactory
{
    /** @param list<string> $allowedPrivateNetworks */
    public static function create(
        HttpClientInterface $transport,
        array $allowedPrivateNetworks = [],
        float $timeout = 15,
        float $maxDuration = 30,
    ): HttpClientInterface {
        $subnets = [] === $allowedPrivateNetworks ? null : SubnetCalculator::subtract(IpUtils::PRIVATE_SUBNETS, $allowedPrivateNetworks);

        return (new NoPrivateNetworkHttpClient($transport, $subnets))->withOptions([
            'timeout' => $timeout,
            'max_duration' => $maxDuration,
            'max_redirects' => 0,
            'proxy' => null,
            'no_proxy' => '*',
        ]);
    }
}
