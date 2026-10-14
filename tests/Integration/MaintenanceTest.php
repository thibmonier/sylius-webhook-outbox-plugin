<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;

final class MaintenanceTest extends KernelTestCase
{
    use WebhookTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->receiver()->reset();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testAnonymizationRedactsPayloadsAndResponsesAndBlocksReplays(): void
    {
        $endpoint = $this->createEndpoint(['customer.created', 'order.placed']);
        $customer = $this->newCustomer();
        $order = $this->newCompletedOrder($customer);
        $order->setNotes('Leave it with the neighbour, Ada');
        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        $this->receiver()->queue(new MockResponse('{"received":"ada@example.com"}', ['http_code' => 200]));
        $dispatcher = self::getContainer()->get('tmonier_webhook.dispatcher');
        self::assertInstanceOf(WebhookDispatcher::class, $dispatcher);
        while ($dispatcher->dispatchNext() instanceof \Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface) {
        }

        $tester = $this->command('tmonier:webhook:anonymize-customer');
        $tester->execute(['customer' => (string) $customer->getEmail()]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('2 webhook event(s) anonymized', $tester->getDisplay());

        $this->entityManager()->clear();
        $placed = $this->eventsOfType('order.placed', (string) $this->idOf($order))[0];
        self::assertTrue($placed->isAnonymized());
        $payload = $placed->getPayload();
        self::assertIsArray($payload['customer']);
        self::assertNull($payload['customer']['email']);
        self::assertNull($payload['notes']);
        self::assertSame($order->getNumber(), $payload['number'], 'Business data kept.');

        foreach ($this->deliveriesOf($this->entityManager()->find($endpoint::class, $endpoint->getId()) ?? $endpoint) as $delivery) {
            self::assertNull($delivery->getLastResponseBody());
            self::assertNull($delivery->getAttemptHistory()[0]['response_body']);
            self::assertFalse($delivery->canBeReplayed(), 'Replays of anonymized events are refused.');
        }
    }

    public function testPurgeDeletesOldFinishedEventsOnly(): void
    {
        $endpoint = $this->createEndpoint(['customer.created']);
        foreach ([1, 2] as $ignored) {
            $this->entityManager()->persist($this->newCustomer());
        }
        $this->entityManager()->flush();
        [$finished, $open] = $this->deliveriesOf($endpoint);
        $finished->markDelivered(new \DateTimeImmutable());
        $this->entityManager()->flush();
        $this->connection()->executeStatement(
            'UPDATE tmonier_webhook_outbox_event SET occurred_at = ? WHERE id IN (?, ?)',
            ['2020-01-01 00:00:00', $finished->getEvent()->getId(), $open->getEvent()->getId()],
        );
        $finishedId = $finished->getEvent()->getId();
        $openId = $open->getEvent()->getId();

        $dryRun = $this->command('tmonier:webhook:purge');
        $dryRun->execute(['--dry-run' => true]);
        self::assertStringContainsString('1 event(s) would be deleted.', $dryRun->getDisplay());

        $purge = $this->command('tmonier:webhook:purge');
        $purge->execute([]);
        $purge->assertCommandIsSuccessful();
        self::assertStringContainsString('1 event(s) deleted', $purge->getDisplay());

        $this->entityManager()->clear();
        $repository = $this->entityManager()->getRepository(OutboxEventInterface::class);
        self::assertNull($repository->find($finishedId));
        self::assertNotNull($repository->find($openId), 'Events with a delivery still to do are kept.');
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM tmonier_webhook_delivery WHERE event_id = ?', [$finishedId]), 'Deliveries deleted with their event.');
    }

    private function command(string $name): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find($name));
    }
}
