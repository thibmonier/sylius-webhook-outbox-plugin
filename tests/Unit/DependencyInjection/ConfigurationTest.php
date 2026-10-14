<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tmonier\SyliusWebhookOutboxPlugin\DependencyInjection\Configuration;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = $this->process([]);

        self::assertTrue($config['enabled']);
        self::assertSame([], $config['events']);
        self::assertSame(Configuration::DEFAULT_RETRY_SCHEDULE, $config['retry_schedule']);
        self::assertEquals(15, $config['timeout']);
        self::assertEquals(30, $config['max_duration']);
        self::assertSame(2048, $config['response_body_max_bytes']);
        self::assertSame(30, $config['retention_days']);
        self::assertFalse($config['allow_http_endpoints']);
        self::assertSame([], $config['allowed_private_networks']);
    }

    public function testCustomValues(): void
    {
        $config = $this->process([
            'events' => ['product_variant.stock_changed' => ['enabled' => false], 'order.paid' => null],
            'retry_schedule' => [0, 60],
            'retention_days' => null,
            'allowed_private_networks' => ['10.0.12.0/24', '192.168.1.20', 'fd00::/8'],
        ]);

        self::assertSame([
            'product_variant.stock_changed' => ['enabled' => false],
            'order.paid' => ['enabled' => true],
        ], $config['events']);
        self::assertSame([0, 60], $config['retry_schedule']);
        self::assertNull($config['retention_days']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigs(): iterable
    {
        yield 'empty schedule' => [['retry_schedule' => []]];
        yield 'negative delay' => [['retry_schedule' => [0, -5]]];
        yield 'bad network' => [['allowed_private_networks' => ['10.0.0.0/33']]];
        yield 'not an ip' => [['allowed_private_networks' => ['intranet']]];
        yield 'bad retention' => [['retention_days' => 0]];
        yield 'timeout' => [['timeout' => 0]];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<string, mixed> $config
     */
    public function testInvalid(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->process($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);

        return $processed;
    }
}
