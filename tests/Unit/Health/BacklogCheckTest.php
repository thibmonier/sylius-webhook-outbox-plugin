<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthResult;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusWebhookOutboxPlugin\Health\BacklogCheck;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;

final class BacklogCheckTest extends TestCase
{
    private const NOW = '2026-11-27 12:00:00';

    /**
     * One row per row of the table of cases of US-304: just under, on and just over each border.
     *
     * @return iterable<string, array{int|null, HealthStatus}>
     */
    public static function ages(): iterable
    {
        yield 'nothing is due' => [null, HealthStatus::Ok];
        yield 'due for one second' => [1, HealthStatus::Ok];
        yield '15 minutes exactly' => [900, HealthStatus::Ok];
        yield '15 minutes and one second' => [901, HealthStatus::Warning];
        yield '60 minutes exactly' => [3600, HealthStatus::Warning];
        yield '60 minutes and one second' => [3601, HealthStatus::Critical];
    }

    #[DataProvider('ages')]
    public function testTheStatusFollowsTheAgeOfTheOldestDueDelivery(?int $ageInSeconds, HealthStatus $expected): void
    {
        self::assertSame($expected, $this->check($ageInSeconds)->run()->status);
    }

    public function testTheSummaryGivesTheAgeInMinutesRoundedUp(): void
    {
        $summary = $this->check(3601)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_webhook.health.backlog.late', $summary->getMessage());
        self::assertSame(['%minutes%' => 61], $summary->getParameters());
    }

    public function testAHealthySummaryCarriesNoParameter(): void
    {
        $summary = $this->check(null)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_webhook.health.backlog.ok', $summary->getMessage());
    }

    public function testALateBacklogLinksToTheDeliveriesGrid(): void
    {
        $result = $this->check(901)->run();

        self::assertNotNull($result->action);
        self::assertSame('tmonier_webhook_admin_webhook_delivery_index', $result->action->route);
    }

    public function testTheAnswerNeverCarriesAnUrlNorAPayload(): void
    {
        $result = $this->check(7200)->run();

        self::assertSame(['%minutes%' => 120], $this->parameters($result));
        self::assertNull($result->detail);
    }

    public function testTheProbeBelongsToThePluginsCategoryWithAFixedIdentifier(): void
    {
        $check = $this->check(null);

        self::assertSame('webhook_outbox.backlog', $check->identifier());
        self::assertSame(HealthCategory::Plugins, $check->category());
    }

    public function testAFailingQueryMakesTheProbeUnknownWithoutTheCause(): void
    {
        $deliveries = $this->createStub(WebhookDeliveryRepositoryInterface::class);
        $deliveries->method('oldestPendingDueAt')->willThrowException(new \RuntimeException('SQLSTATE[HY000] [2002] mysql://shop:secret@db/sylius'));

        $reports = (new HealthCheckRunner([new BacklogCheck($deliveries, new MockClock(self::NOW))], new NullLogger()))->run();

        self::assertCount(1, $reports);
        self::assertSame(HealthStatus::Unknown, $reports[0]->result->status);
        self::assertSame('tmonier_platform_health.result.check_failed', $this->message($reports[0]->result));
    }

    private function check(?int $ageInSeconds): BacklogCheck
    {
        $clock = new MockClock(self::NOW);
        $deliveries = $this->createStub(WebhookDeliveryRepositoryInterface::class);
        $deliveries->method('oldestPendingDueAt')->willReturn(null === $ageInSeconds ? null : $clock->now()->modify(sprintf('-%d seconds', $ageInSeconds)));

        return new BacklogCheck($deliveries, $clock);
    }

    /** @return array<mixed> */
    private function parameters(HealthResult $result): array
    {
        self::assertInstanceOf(TranslatableMessage::class, $result->summary);

        return $result->summary->getParameters();
    }

    private function message(HealthResult $result): string
    {
        self::assertInstanceOf(TranslatableMessage::class, $result->summary);

        return $result->summary->getMessage();
    }
}
