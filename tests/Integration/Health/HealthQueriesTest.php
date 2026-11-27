<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookEndpointRepositoryInterface;

/**
 * The aggregate queries behind the health probes, against the real schema: no entity is loaded, the indexes serve them.
 */
final class HealthQueriesTest extends KernelTestCase
{
    use WebhookTestTrait;

    private const NOW = '2026-11-27 12:00:00';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
        $this->connection()->executeStatement('DELETE FROM tmonier_webhook_delivery');
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testNoPendingDeliveryMeansNoBacklog(): void
    {
        self::assertNull($this->deliveries()->oldestPendingDueAt($this->now()));
    }

    public function testTheOldestDuePendingDeliveryGivesTheDateOfTheBacklog(): void
    {
        $this->createDeliveries([
            [WebhookDeliveryInterface::STATUS_PENDING, '2026-11-27 11:40:00'],
            [WebhookDeliveryInterface::STATUS_PENDING, '2026-11-27 11:55:00'],
        ]);

        self::assertEquals(new \DateTimeImmutable('2026-11-27 11:40:00', new \DateTimeZone('UTC')), $this->deliveries()->oldestPendingDueAt($this->now()));
    }

    public function testADeliveryDueExactlyNowIsDue(): void
    {
        $this->createDeliveries([[WebhookDeliveryInterface::STATUS_PENDING, self::NOW]]);

        self::assertEquals($this->now(), $this->deliveries()->oldestPendingDueAt($this->now()));
    }

    public function testADeliveryDueInTheFutureIsNotDue(): void
    {
        $this->createDeliveries([[WebhookDeliveryInterface::STATUS_PENDING, '2026-11-27 12:00:01']]);

        self::assertNull($this->deliveries()->oldestPendingDueAt($this->now()));
    }

    public function testOnlyPendingDeliveriesCount(): void
    {
        $this->createDeliveries([
            [WebhookDeliveryInterface::STATUS_FAILED, '2026-11-27 09:00:00'],
            [WebhookDeliveryInterface::STATUS_SUSPENDED, '2026-11-27 09:00:00'],
            [WebhookDeliveryInterface::STATUS_DEAD, '2026-11-27 09:00:00'],
        ]);

        self::assertNull($this->deliveries()->oldestPendingDueAt($this->now()));
    }

    public function testDeadDeliveriesAreCountedUpToTheLimit(): void
    {
        $this->createDeliveries(array_fill(0, 3, [WebhookDeliveryInterface::STATUS_DEAD, '2026-11-27 09:00:00']));

        self::assertSame(3, $this->deliveries()->countDeadUpTo(501));
        self::assertSame(2, $this->deliveries()->countDeadUpTo(2));
    }

    public function testOtherStatusesAreNotDeadDeliveries(): void
    {
        $this->createDeliveries([[WebhookDeliveryInterface::STATUS_FAILED, '2026-11-27 09:00:00']]);

        self::assertSame(0, $this->deliveries()->countDeadUpTo(501));
    }

    public function testOnlyEndpointsDisabledByThePluginAreCounted(): void
    {
        $before = $this->endpoints()->countDisabledAutomatically();
        $gone = $this->createEndpoint(['customer.created'], flush: false);
        $gone->disable('The endpoint answered 410 Gone.', new \DateTimeImmutable());
        $byAnAdministrator = $this->createEndpoint(['customer.created'], flush: false);
        $byAnAdministrator->setEnabled(false);
        $this->createEndpoint(['customer.created'], flush: false);
        $this->entityManager()->flush();

        self::assertSame($before + 1, $this->endpoints()->countDisabledAutomatically());
    }

    public function testAnEndpointEnabledAgainIsNoLongerCounted(): void
    {
        $before = $this->endpoints()->countDisabledAutomatically();
        $endpoint = $this->createEndpoint(['customer.created']);
        $endpoint->disable('The endpoint answered 410 Gone.', new \DateTimeImmutable());
        $this->entityManager()->flush();
        $endpoint->setEnabled(true);
        $this->entityManager()->flush();

        self::assertSame($before, $this->endpoints()->countDisabledAutomatically());
    }

    /**
     * @param list<array{string, string}> $rows status and next attempt date (UTC) of each delivery
     */
    private function createDeliveries(array $rows): void
    {
        $endpoint = $this->createEndpoint(['customer.created']);
        foreach ($rows as $ignored) {
            $this->entityManager()->persist($this->newCustomer());
        }
        $this->entityManager()->flush();

        foreach ($this->deliveriesOf($endpoint) as $index => $delivery) {
            $this->connection()->executeStatement(
                'UPDATE tmonier_webhook_delivery SET status = ?, next_attempt_at = ? WHERE id = ?',
                [$rows[$index][0], $rows[$index][1], $this->idOf($delivery)],
            );
        }
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function deliveries(): WebhookDeliveryRepositoryInterface
    {
        $repository = self::getContainer()->get('tmonier_webhook.repository.webhook_delivery');
        self::assertInstanceOf(WebhookDeliveryRepositoryInterface::class, $repository);

        return $repository;
    }

    private function endpoints(): WebhookEndpointRepositoryInterface
    {
        $repository = self::getContainer()->get('tmonier_webhook.repository.webhook_endpoint');
        self::assertInstanceOf(WebhookEndpointRepositoryInterface::class, $repository);

        return $repository;
    }
}
