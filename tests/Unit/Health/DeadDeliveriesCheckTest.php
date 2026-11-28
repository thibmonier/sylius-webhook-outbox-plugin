<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Unit\Health;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\TranslatableMessage;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCategory;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusWebhookOutboxPlugin\Health\DeadDeliveriesCheck;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;

final class DeadDeliveriesCheckTest extends TestCase
{
    /**
     * One row per row of the table of cases of US-304.
     *
     * @return iterable<string, array{int, int, HealthStatus}>
     */
    public static function counts(): iterable
    {
        yield 'no dead delivery, no disabled endpoint' => [0, 0, HealthStatus::Ok];
        yield 'one dead delivery' => [1, 0, HealthStatus::Warning];
        yield 'one disabled endpoint' => [0, 1, HealthStatus::Warning];
        yield 'both' => [3, 2, HealthStatus::Warning];
    }

    #[DataProvider('counts')]
    public function testTheStatusFollowsTheDeadDeliveriesAndTheDisabledEndpoints(int $dead, int $disabled, HealthStatus $expected): void
    {
        self::assertSame($expected, $this->check($dead, $disabled)->run()->status);
    }

    public function testTheSummaryGivesBothCounts(): void
    {
        $summary = $this->check(3, 2)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_webhook.health.dead.found', $summary->getMessage());
        self::assertSame(['%dead%' => '3', '%disabled%' => '2'], $summary->getParameters());
    }

    public function testAHealthySummaryCarriesNoParameter(): void
    {
        $summary = $this->check(0, 0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('tmonier_webhook.health.dead.ok', $summary->getMessage());
    }

    public function testFiveHundredDeadDeliveriesAreCountedExactly(): void
    {
        $summary = $this->check(500, 0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500', $summary->getParameters()['%dead%']);
    }

    public function testBeyondFiveHundredTheSummarySaysMoreThanFiveHundred(): void
    {
        $summary = $this->check(501, 0)->run()->summary;

        self::assertInstanceOf(TranslatableMessage::class, $summary);
        self::assertSame('500+', $summary->getParameters()['%dead%']);
    }

    public function testTheDeadDeliveriesAreCountedWithoutGoingBeyondFiveHundredAndOne(): void
    {
        $deliveries = $this->createMock(WebhookDeliveryRepositoryInterface::class);
        $deliveries->expects(self::once())->method('countDeadUpTo')->with(501)->willReturn(0);

        (new DeadDeliveriesCheck($deliveries, $this->endpoints(0)))->run();
    }

    public function testDeadDeliveriesLinkToTheDeadLettersGrid(): void
    {
        $result = $this->check(1, 1)->run();

        self::assertNotNull($result->action);
        self::assertSame('tmonier_webhook_admin_webhook_delivery_dead_letters', $result->action->route);
    }

    public function testDisabledEndpointsAloneLinkToTheEndpointsGrid(): void
    {
        $result = $this->check(0, 1)->run();

        self::assertNotNull($result->action);
        self::assertSame('tmonier_webhook_admin_webhook_endpoint_index', $result->action->route);
    }

    public function testTheProbeBelongsToThePluginsCategoryWithAFixedIdentifier(): void
    {
        $check = $this->check(0, 0);

        self::assertSame('webhook_outbox.dead_deliveries', $check->identifier());
        self::assertSame(HealthCategory::Plugins, $check->category());
    }

    public function testAFailingQueryMakesTheProbeUnknownWithoutTheCause(): void
    {
        $deliveries = $this->createStub(WebhookDeliveryRepositoryInterface::class);
        $deliveries->method('countDeadUpTo')->willThrowException(new \RuntimeException('Connection refused to db.internal:3306'));

        $reports = (new HealthCheckRunner([new DeadDeliveriesCheck($deliveries, $this->endpoints(0))], new NullLogger()))->run();

        self::assertSame(HealthStatus::Unknown, $reports[0]->result->status);
    }

    private function check(int $dead, int $disabled): DeadDeliveriesCheck
    {
        $deliveries = $this->createStub(WebhookDeliveryRepositoryInterface::class);
        $deliveries->method('countDeadUpTo')->willReturn($dead);

        return new DeadDeliveriesCheck($deliveries, $this->endpoints($disabled));
    }

    private function endpoints(int $disabled): WebhookEndpointRepositoryInterface
    {
        $endpoints = $this->createStub(WebhookEndpointRepositoryInterface::class);
        $endpoints->method('countDisabledAutomatically')->willReturn($disabled);

        return $endpoints;
    }
}
