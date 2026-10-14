<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Delivery;

use PHPUnit\Framework\TestCase;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\RetryPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\DependencyInjection\Configuration;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;

final class RetryPolicyTest extends TestCase
{
    public function testDefaultScheduleWithoutJitter(): void
    {
        $policy = new RetryPolicy(Configuration::DEFAULT_RETRY_SCHEDULE, 0.0);
        $failure = $this->attempt(500);

        self::assertSame(10, $policy->maxAttempts());
        self::assertSame(0, $policy->firstAttemptDelay());
        $delays = [];
        for ($done = 1; $done <= 10; ++$done) {
            $delays[] = $policy->nextDelay($done, $failure);
        }
        self::assertSame([5, 300, 1800, 7200, 18000, 36000, 50400, 72000, 86400, null], $delays);
    }

    public function testJitterIsBoundedByTheRatio(): void
    {
        $maxima = [];
        $policy = new RetryPolicy([0, 100], 0.1, static function (int $max) use (&$maxima): int {
            $maxima[] = $max;

            return $max;
        });

        self::assertSame(110, $policy->nextDelay(1, $this->attempt(500)));
        self::assertSame([10], $maxima);
    }

    public function testRetryAfterOnlyOnThrottlingStatusesAndCapped(): void
    {
        $policy = new RetryPolicy([0, 5, 300], 0.0);

        self::assertSame(120, $policy->nextDelay(1, $this->attempt(429, 120)));
        self::assertSame(120, $policy->nextDelay(1, $this->attempt(503, 120)));
        self::assertSame(5, $policy->nextDelay(1, $this->attempt(500, 120)), 'Ignored on 500.');
        self::assertSame(300, $policy->nextDelay(2, $this->attempt(429, 10)), 'Never shorter than the schedule.');
        self::assertSame(RetryPolicy::MAX_RETRY_AFTER, $policy->nextDelay(1, $this->attempt(429, 10_000_000)));
    }

    public function testParseRetryAfter(): void
    {
        $now = new \DateTimeImmutable('2026-10-11 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame(30, RetryPolicy::parseRetryAfter('30', $now));
        self::assertSame(90, RetryPolicy::parseRetryAfter('Sun, 11 Oct 2026 10:01:30 GMT', $now));
        self::assertSame(0, RetryPolicy::parseRetryAfter('Sun, 11 Oct 2026 09:00:00 GMT', $now));
        self::assertSame(90, RetryPolicy::parseRetryAfter('Sunday, 11-Oct-26 10:01:30 GMT', $now), 'RFC 850.');
        self::assertSame(90, RetryPolicy::parseRetryAfter('Sun Oct 11 10:01:30 2026', $now), 'asctime.');
        self::assertSame(90, RetryPolicy::parseRetryAfter('Sun Oct  11 10:01:30 2026', $now), 'asctime, padded day.');
        self::assertNull(RetryPolicy::parseRetryAfter('soon', $now));
        self::assertNull(RetryPolicy::parseRetryAfter(null, $now));
    }

    public function testEmptyScheduleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetryPolicy([]);
    }

    private function attempt(int $status, ?int $retryAfter = null): DeliveryAttempt
    {
        return new DeliveryAttempt(new \DateTimeImmutable(), 10, $status, null, null, $retryAfter);
    }
}
