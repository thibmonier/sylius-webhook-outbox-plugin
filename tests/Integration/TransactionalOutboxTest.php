<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\ForUpdate\ConflictResolutionMode;
use Doctrine\Persistence\ManagerRegistry;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\DeliveryClaimer;

/**
 * The outbox guarantee, with REAL commits and rollbacks (no wrapping test transaction): an
 * event row is committed if and only if the business change is. A second connection checks
 * what is really committed. Every test deletes what it created (tearDown).
 *
 * Runs on MySQL, MariaDB and PostgreSQL in CI.
 */
final class TransactionalOutboxTest extends KernelTestCase
{
    use WebhookTestTrait;

    private Connection $observer;

    /** @var list<int> */
    private array $endpointIds = [];

    /** @var list<string> customer e-mails created by the test */
    private array $emails = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->observer = DriverManager::getConnection($this->connection()->getParams());
        $endpoint = $this->createEndpoint();
        $this->endpointIds[] = $this->idOf($endpoint);
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        $connection = $this->observer;

        foreach ($this->emails as $email) {
            $customerId = $connection->fetchOne('SELECT id FROM sylius_customer WHERE email = ?', [$email]);
            if (false !== $customerId) {
                foreach ($connection->fetchFirstColumn('SELECT id FROM sylius_order WHERE customer_id = ?', [$customerId]) as $orderId) {
                    $connection->delete('sylius_order', ['id' => $orderId]);
                }
                $connection->delete('sylius_customer', ['id' => $customerId]);
            }
            $connection->executeStatement('DELETE FROM tmonier_webhook_outbox_event WHERE customer_id = ?', [$customerId ?: 0]);
        }
        foreach ($this->endpointIds as $endpointId) {
            $eventIds = $connection->fetchFirstColumn('SELECT event_id FROM tmonier_webhook_delivery WHERE endpoint_id = ?', [$endpointId]);
            $connection->delete('tmonier_webhook_endpoint', ['id' => $endpointId]);
            foreach ($eventIds as $eventId) {
                $connection->delete('tmonier_webhook_outbox_event', ['id' => $eventId]);
            }
        }
        $connection->close();

        parent::tearDown();
    }

    public function testEventsAreCommittedWithTheBusinessChangeAndReferToTheNewIds(): void
    {
        $customer = $this->newCustomer();
        $this->emails[] = (string) $customer->getEmail();
        $order = $this->newCompletedOrder($customer);
        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        $orderId = $this->idOf($order);
        $customerId = $this->idOf($customer);

        $placed = $this->committedEvents('order.placed', (string) $orderId);
        self::assertCount(1, $placed, 'order.placed is committed with the order.');
        self::assertSame($customerId, (int) $placed[0]['customer_id'], 'customer_id of a customer inserted in the same flush is resolved.');
        $payload = json_decode((string) $placed[0]['payload'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($orderId, $payload['id']);
        self::assertSame($order->getNumber(), $payload['number']);
        self::assertIsArray($payload['customer']);
        self::assertSame($customerId, $payload['customer']['id']);
        self::assertStringEndsWith('/admin/orders/' . $orderId, (string) $payload['admin_url']);

        $created = $this->committedEvents('customer.created', (string) $customerId);
        self::assertCount(1, $created, 'customer.created is committed with the customer.');
        self::assertSame(
            1,
            (int) $this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_delivery WHERE event_id = ? AND status = ?', [$created[0]['id'], 'pending']),
            'One pending delivery per subscribed endpoint.',
        );

        // An update in a later transaction: order.paid only.
        $order->setPaymentState(OrderPaymentStates::STATE_PAID);
        $this->entityManager()->flush();
        self::assertCount(1, $this->committedEvents('order.paid', (string) $orderId));
        self::assertCount(1, $this->committedEvents('order.placed', (string) $orderId), 'No duplicate on the next flush.');
    }

    public function testNothingIsCommittedWhenTheTransactionIsRolledBack(): void
    {
        $customer = $this->newCustomer();
        $email = (string) $customer->getEmail();
        $this->emails[] = $email;

        $this->connection()->beginTransaction();
        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($this->newCompletedOrder($customer));
        $this->entityManager()->flush();
        $customerId = $this->idOf($customer);

        self::assertGreaterThan(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event WHERE customer_id = ?', [$customerId]), 'Written inside the transaction.');
        self::assertSame(0, $this->committedCount($customerId), 'Not visible from another connection before the commit.');

        $this->connection()->rollBack();

        self::assertSame(0, $this->committedCount($customerId), 'Nothing committed after the rollback.');
        self::assertFalse($this->observer->fetchOne('SELECT id FROM sylius_customer WHERE email = ?', [$email]));
    }

    public function testNothingIsCommittedWhenTheFlushFails(): void
    {
        $existing = $this->newCustomer();
        $this->emails[] = (string) $existing->getEmail();
        $this->entityManager()->persist($existing);
        $this->entityManager()->flush();
        $eventsBefore = (int) $this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event');

        // Same e-mail: the unique index fails the flush after the event rows were written.
        $duplicate = $this->newCustomer((string) $existing->getEmail());
        $order = $this->newCompletedOrder($this->newCustomer());
        $this->emails[] = (string) $order->getCustomer()?->getEmail();
        $this->entityManager()->persist($order);
        $this->entityManager()->persist($duplicate);

        try {
            $this->entityManager()->flush();
            self::fail('The flush should fail on the duplicate e-mail.');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame($eventsBefore, (int) $this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event'), 'No event committed when the flush fails.');
        self::assertFalse($this->observer->fetchOne('SELECT id FROM sylius_order WHERE number = ?', [$order->getNumber()]));

        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
    }

    public function testConcurrentWorkersNeverClaimTheSameDelivery(): void
    {
        $customer = $this->newCustomer();
        $this->emails[] = (string) $customer->getEmail();
        $this->entityManager()->persist($customer);
        $this->entityManager()->persist($this->newCompletedOrder($customer));
        $this->entityManager()->flush();

        $claimer = self::getContainer()->get('tmonier_webhook.delivery_claimer');
        self::assertInstanceOf(DeliveryClaimer::class, $claimer);
        if (!$claimer->isSkipLockedSupported()) {
            self::markTestSkipped('SKIP LOCKED is not supported by this database platform.');
        }

        // Worker B locks one due delivery in an open transaction...
        $this->observer->beginTransaction();
        $lockedId = $this->observer->createQueryBuilder()
            ->select('id')
            ->from('tmonier_webhook_delivery')
            ->where('endpoint_id = :endpoint')
            ->orderBy('next_attempt_at')
            ->addOrderBy('id')
            ->setMaxResults(1)
            ->setParameter('endpoint', $this->endpointIds[0])
            ->forUpdate(ConflictResolutionMode::SKIP_LOCKED)
            ->executeQuery()
            ->fetchOne()
        ;
        self::assertNotFalse($lockedId);

        try {
            // ...worker A skips it instead of waiting, and claims another one.
            $claims = [];
            while (null !== $claim = $claimer->claimNext()) {
                $claims[] = $claim->deliveryId;
                if (count($claims) > 50) {
                    break;
                }
            }
            self::assertNotContains((int) $lockedId, $claims);
            self::assertNotEmpty(array_intersect($claims, $this->deliveryIdsOfTestEndpoint()), 'The other delivery of the test endpoint is claimed.');
        } finally {
            $this->observer->rollBack();
        }
    }

    /** @return list<array<string, mixed>> */
    private function committedEvents(string $type, string $resourceId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->observer->fetchAllAssociative(
            'SELECT * FROM tmonier_webhook_outbox_event WHERE type = ? AND resource_id = ?',
            [$type, $resourceId],
        );

        return $rows;
    }

    private function committedCount(int $customerId): int
    {
        return (int) $this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event WHERE customer_id = ?', [$customerId]);
    }

    /** @return list<int> */
    private function deliveryIdsOfTestEndpoint(): array
    {
        return array_map('intval', $this->observer->fetchFirstColumn('SELECT id FROM tmonier_webhook_delivery WHERE endpoint_id = ?', [$this->endpointIds[0]]));
    }
}
