<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration\Health;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckReport;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthCheckRunner;
use Tmonier\SyliusPlatformHealthPlugin\Health\HealthStatus;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;

/**
 * The probes as the health page runs them: through the runner of the health plugin, against the test application.
 */
final class HealthProbesTest extends KernelTestCase
{
    use WebhookTestTrait;

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

    public function testBothProbesAreOkWhenThePluginIsHealthy(): void
    {
        self::assertSame(
            ['webhook_outbox.backlog' => HealthStatus::Ok, 'webhook_outbox.dead_deliveries' => HealthStatus::Ok],
            $this->statuses(),
        );
    }

    public function testADeliveryDueForMoreThanAnHourMakesTheBacklogCritical(): void
    {
        $this->createDelivery(WebhookDeliveryInterface::STATUS_PENDING, '-61 minutes');

        self::assertSame(HealthStatus::Critical, $this->statuses()['webhook_outbox.backlog'] ?? null);
    }

    public function testADeadDeliveryMakesTheProbeWarn(): void
    {
        $this->createDelivery(WebhookDeliveryInterface::STATUS_DEAD, '-1 day');

        self::assertSame(HealthStatus::Warning, $this->statuses()['webhook_outbox.dead_deliveries'] ?? null);
    }

    private function createDelivery(string $status, string $due): void
    {
        $endpoint = $this->createEndpoint(['customer.created']);
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();
        [$delivery] = $this->deliveriesOf($endpoint);

        $this->connection()->executeStatement(
            'UPDATE tmonier_webhook_delivery SET status = ?, next_attempt_at = ? WHERE id = ?',
            [$status, (new \DateTimeImmutable($due, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), $this->idOf($delivery)],
        );
    }

    /**
     * @return array<string, HealthStatus>
     */
    private function statuses(): array
    {
        $runner = self::getContainer()->get('tmonier_sylius_platform_health.runner');
        self::assertInstanceOf(HealthCheckRunner::class, $runner);

        $statuses = [];
        foreach ($runner->run() as $report) {
            self::assertInstanceOf(HealthCheckReport::class, $report);
            if (str_starts_with($report->identifier, 'webhook_outbox.')) {
                $statuses[$report->identifier] = $report->result->status;
            }
        }

        return $statuses;
    }
}
