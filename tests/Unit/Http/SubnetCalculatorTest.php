<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;
use Tmonier\SyliusWebhookOutboxPlugin\Http\SubnetCalculator;

final class SubnetCalculatorTest extends TestCase
{
    public function testHoleInsideANetwork(): void
    {
        $result = SubnetCalculator::subtract(['10.0.0.0/8'], ['10.0.12.0/24']);

        self::assertFalse(IpUtils::checkIp('10.0.12.7', $result), 'Allowed.');
        self::assertTrue(IpUtils::checkIp('10.0.13.7', $result), 'Still blocked.');
        self::assertTrue(IpUtils::checkIp('10.200.0.1', $result));
        self::assertTrue(IpUtils::checkIp('10.0.11.255', $result));
        self::assertCount(16, $result, 'One sibling per bit between /8 and /24.');
    }

    public function testWholeNetworkAllowedAndDisjointNetworks(): void
    {
        self::assertSame(['192.168.0.0/16'], SubnetCalculator::subtract(['10.0.0.0/8', '192.168.0.0/16'], ['10.0.0.0/8']));
        self::assertSame(['127.0.0.0/8'], SubnetCalculator::subtract(['127.0.0.0/8'], ['10.1.2.3']));
    }

    public function testSingleAddressAndIpv6(): void
    {
        $result = SubnetCalculator::subtract(['172.16.0.0/12', 'fc00::/7'], ['172.16.5.10', 'fd12:3456::/32']);

        self::assertFalse(IpUtils::checkIp('172.16.5.10', $result));
        self::assertTrue(IpUtils::checkIp('172.16.5.11', $result));
        self::assertFalse(IpUtils::checkIp('fd12:3456::1', $result));
        self::assertTrue(IpUtils::checkIp('fd12:3457::1', $result));
    }

    public function testInvalidRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SubnetCalculator::subtract(['10.0.0.0/8'], ['nope']);
    }
}
