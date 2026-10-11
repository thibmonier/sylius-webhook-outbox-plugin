<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Sylius\Component\Core\OrderPaymentStates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\DeliveryClaimer;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\RetryPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookRequest;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookSenderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDelivery;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\CustomerWebhookDataAnonymizer;
use Tmonier\SyliusWebhookOutboxPlugin\Maintenance\PayloadRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\WebhookDeliveryRepository;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

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
        self::assertSame($customerId, self::int($placed[0]['customer_id']), 'customer_id of a customer inserted in the same flush is resolved.');
        $payload = json_decode(self::string($placed[0]['payload']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame($orderId, $payload['id']);
        self::assertSame($order->getNumber(), $payload['number']);
        self::assertIsArray($payload['customer']);
        self::assertSame($customerId, $payload['customer']['id']);
        self::assertStringEndsWith('/admin/orders/' . $orderId, self::string($payload['admin_url']));

        $created = $this->committedEvents('customer.created', (string) $customerId);
        self::assertCount(1, $created, 'customer.created is committed with the customer.');
        self::assertSame(
            1,
            self::int($this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_delivery WHERE event_id = ? AND status = ?', [$created[0]['id'], 'pending'])),
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

        self::assertGreaterThan(0, self::int($this->connection()->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event WHERE customer_id = ?', [$customerId])), 'Written inside the transaction.');
        self::assertSame(0, $this->committedCount($customerId), 'Not visible from another connection before the commit.');

        $this->connection()->rollBack();

        self::assertSame(0, $this->committedCount($customerId), 'Nothing committed after the rollback.');
        self::assertFalse($this->observer->fetchOne('SELECT id FROM sylius_customer WHERE email = ?', [$email]));
    }

    public function testNothingIsCommittedWhenTheFlushFailsAfterTheEventInsert(): void
    {
        $first = $this->newCustomer();
        $second = $this->newCustomer();
        $this->emails[] = (string) $first->getEmail();
        $this->emails[] = (string) $second->getEmail();
        $this->entityManager()->persist($first);
        $this->entityManager()->persist($second);
        $this->entityManager()->flush();
        $eventsBefore = self::int($this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event'));

        // Doctrine runs the INSERTs (order, outbox event, deliveries) before the UPDATEs: the
        // duplicate e-mail of the second customer fails the flush after the event was written.
        $order = $this->newCompletedOrder($first);
        $this->entityManager()->persist($order);
        $second->setEmail((string) $first->getEmail());
        $second->setEmailCanonical((string) $first->getEmailCanonical());

        $inserts = new class() {
            public int $events = 0;

            public function postPersist(PostPersistEventArgs $args): void
            {
                if ($args->getObject() instanceof OutboxEventInterface) {
                    ++$this->events;
                }
            }
        };
        $eventManager = $this->entityManager()->getEventManager();
        $eventManager->addEventListener([Events::postPersist], $inserts);

        try {
            $this->entityManager()->flush();
            self::fail('The flush should fail on the duplicate e-mail.');
        } catch (UniqueConstraintViolationException) {
        } finally {
            $eventManager->removeEventListener([Events::postPersist], $inserts);
        }

        self::assertGreaterThan(0, $inserts->events, 'The order.placed row was inserted before the failure.');
        self::assertSame($eventsBefore, self::int($this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event')), 'No event committed when the flush fails.');
        self::assertFalse($this->observer->fetchOne('SELECT id FROM sylius_order WHERE number = ?', [$order->getNumber()]));

        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
    }

    public function testAFailedRecordReleasesTheClaimAndTheWorkerRecovers(): void
    {
        $deliveryId = $this->committedDelivery();

        $broken = $this->dispatcherWith(static fn (WebhookRequest $request): DeliveryAttempt => new DeliveryAttempt(new \DateTimeImmutable(), 3, 200, "\xB1\x31 invalid UTF-8"));

        try {
            $broken->dispatchNext();
            self::fail('Recording an attempt that cannot be encoded must fail.');
        } catch (\Throwable) {
        }

        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertTrue($entityManager->isOpen(), 'The closed entity manager was reset.');
        $row = $this->deliveryRow($deliveryId);
        self::assertNull($row['claim_token'], 'The claim was released.');
        self::assertSame('pending', $row['status']);
        self::assertSame(0, self::int($row['attempts']));

        $delivery = $this->dispatcherWith(static fn (WebhookRequest $request): DeliveryAttempt => new DeliveryAttempt(new \DateTimeImmutable(), 3, 204))->dispatchNext();
        self::assertNotNull($delivery);
        self::assertSame('delivered', $this->deliveryRow($deliveryId)['status'], 'The same worker keeps sending.');
    }

    public function testALostLeaseDropsTheAttemptResult(): void
    {
        $deliveryId = $this->committedDelivery();
        $observer = $this->observer;

        // Another worker took the delivery over while the request was running.
        $this->dispatcherWith(static function (WebhookRequest $request) use ($observer, $deliveryId): DeliveryAttempt {
            $observer->update('tmonier_webhook_delivery', ['claim_token' => 'other-worker'], ['id' => $deliveryId]);

            return new DeliveryAttempt(new \DateTimeImmutable(), 3, 500, 'late');
        })->dispatchNext();

        $row = $this->deliveryRow($deliveryId);
        self::assertSame('other-worker', $row['claim_token'], 'The other worker keeps its lease.');
        self::assertSame(0, self::int($row['attempts']), 'The late result is not recorded.');
        self::assertSame('pending', $row['status']);
    }

    public function testAnErasureDuringTheRequestIsNotUndone(): void
    {
        $deliveryId = $this->committedDelivery();
        $customerId = self::int($this->observer->fetchOne('SELECT e.customer_id FROM tmonier_webhook_outbox_event e JOIN tmonier_webhook_delivery d ON d.event_id = e.id WHERE d.id = ?', [$deliveryId]));

        $this->dispatcherWith(static fn (WebhookRequest $request): DeliveryAttempt => new DeliveryAttempt(new \DateTimeImmutable(), 3, 500, 'echo: ada@example.com'))->dispatchNext();
        $this->observer->executeStatement('UPDATE tmonier_webhook_delivery SET next_attempt_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $deliveryId]);

        // The erasure runs in another process (another connection) while the request is running:
        // the worker's copy of the delivery still holds the old excerpt.
        $observer = $this->observer;
        $this->dispatcherWith(static function (WebhookRequest $request) use ($observer, $deliveryId, $customerId): DeliveryAttempt {
            $observer->executeStatement('UPDATE tmonier_webhook_outbox_event SET anonymized_at = ? WHERE customer_id = ?', ['2026-10-11 00:00:00', $customerId]);
            $history = json_decode(self::string($observer->fetchOne('SELECT attempt_history FROM tmonier_webhook_delivery WHERE id = ?', [$deliveryId])), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($history);
            $erased = array_map(static fn (mixed $attempt): array => ['response_body' => null] + (is_array($attempt) ? $attempt : []), $history);
            $observer->update('tmonier_webhook_delivery', ['attempt_history' => json_encode($erased), 'last_response_body' => null], ['id' => $deliveryId]);

            return new DeliveryAttempt(new \DateTimeImmutable(), 3, 500, 'echo again: ada@example.com');
        })->dispatchNext();

        $row = $this->deliveryRow($deliveryId);
        self::assertSame(2, self::int($row['attempts']), 'The attempt is recorded.');
        self::assertNull($row['last_response_body'], 'No response excerpt of an anonymized event.');
        $history = json_decode(self::string($row['attempt_history']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertSame([null, null], array_column($history, 'response_body'), 'The erased excerpt is not restored.');
    }

    /** @return iterable<string, array{bool}> */
    public static function priorAttempt(): iterable
    {
        yield 'first attempt' => [false];
        yield 'after a failed attempt' => [true];
    }

    /**
     * The real anonymizer, while a worker commits an attempt on another connection after the
     * anonymizer's first read and before it reaches the delivery.
     *
     * @dataProvider priorAttempt
     */
    public function testTheAnonymizerSeesAnAttemptCommittedDuringItsTransaction(bool $prior): void
    {
        $deliveryId = $this->committedDelivery();
        $customerId = self::int($this->observer->fetchOne('SELECT e.customer_id FROM tmonier_webhook_outbox_event e JOIN tmonier_webhook_delivery d ON d.event_id = e.id WHERE d.id = ?', [$deliveryId]));
        if ($prior) {
            $this->observer->update('tmonier_webhook_delivery', [
                'attempts' => 1,
                'status' => 'failed',
                'last_response_body' => 'echo prior: ada@example.com',
                'attempt_history' => json_encode([['at' => '2026-10-11T00:00:00Z', 'duration_ms' => 3, 'status_code' => 500, 'error' => null, 'response_body' => 'echo prior: ada@example.com']]),
            ], ['id' => $deliveryId]);
        }

        $observer = $this->observer;
        $fired = false;
        $workerCommit = static function () use ($observer, $deliveryId, &$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $history = json_decode(self::string($observer->fetchOne('SELECT attempt_history FROM tmonier_webhook_delivery WHERE id = ?', [$deliveryId])), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($history);
            array_unshift($history, ['at' => '2026-10-11T00:01:00Z', 'duration_ms' => 3, 'status_code' => 500, 'error' => null, 'response_body' => 'echo worker: ada@example.com']);
            $observer->executeStatement(
                'UPDATE tmonier_webhook_delivery SET attempts = attempts + 1, status = ?, last_response_body = ?, attempt_history = ? WHERE id = ?',
                ['failed', 'echo worker: ada@example.com', json_encode($history), $deliveryId],
            );
        };

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $deliveries = new class($entityManager, $entityManager->getClassMetadata(WebhookDelivery::class), $workerCommit) extends WebhookDeliveryRepository {
            /** @param ClassMetadata<WebhookDelivery> $class */
            public function __construct(EntityManagerInterface $entityManager, ClassMetadata $class, private readonly \Closure $afterRead)
            {
                parent::__construct($entityManager, $class);
            }

            public function findByEvent(OutboxEventInterface $event): array
            {
                $deliveries = parent::findByEvent($event);
                ($this->afterRead)();

                return $deliveries;
            }
        };
        $events = self::getContainer()->get('tmonier_webhook.repository.outbox_event');
        self::assertInstanceOf(OutboxEventRepositoryInterface::class, $events);

        $anonymizer = new CustomerWebhookDataAnonymizer($events, $deliveries, $entityManager, new PayloadRedactor(), new NativeClock());
        self::assertSame(1, $anonymizer->anonymize($customerId));
        self::assertTrue($fired);

        $row = $this->deliveryRow($deliveryId);
        self::assertNull($row['last_response_body'], 'The worker\'s response excerpt is erased.');
        self::assertSame($prior ? 2 : 1, self::int($row['attempts']));
        $history = json_decode(self::string($row['attempt_history']), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($history);
        self::assertSame($prior ? [null, null] : [null], array_column($history, 'response_body'), 'No excerpt left and no attempt lost.');
    }

    public function testAClaimDoesNotBlockTheCheckoutNorTheOtherWorkers(): void
    {
        $this->committedDelivery();
        $this->committedDelivery();
        $claimer = self::getContainer()->get('tmonier_webhook.delivery_claimer');
        self::assertInstanceOf(DeliveryClaimer::class, $claimer);

        $platform = $this->connection()->getDatabasePlatform();
        $isPostgres = $platform instanceof PostgreSQLPlatform;
        $this->connection()->executeStatement($isPostgres ? "SET lock_timeout = '3s'" : 'SET SESSION innodb_lock_wait_timeout = 3');

        $this->observer->beginTransaction();

        try {
            $held = $claimer->claimOn($this->observer);
            self::assertNotNull($held, 'Worker B holds a claim in an open transaction.');

            // A checkout meanwhile: inserts an event and its delivery.
            $customer = $this->newCustomer();
            $this->emails[] = (string) $customer->getEmail();
            $this->entityManager()->persist($customer);
            $this->entityManager()->flush();
            self::assertNotNull($customer->getId(), 'The insert was not blocked by the claim.');

            if ($claimer->isSkipLockedSupported()) {
                $other = $claimer->claimNext();
                self::assertNotNull($other, 'Worker A claims another delivery instead of nothing.');
                self::assertNotSame($held->deliveryId, $other->deliveryId);
            }
        } finally {
            $this->observer->rollBack();
            $this->connection()->executeStatement($isPostgres ? 'SET lock_timeout = 0' : 'SET SESSION innodb_lock_wait_timeout = 50');
        }
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
        // Lock by primary key: a sorted scan would lock every row it reads.
        $lockedId = $this->observer->createQueryBuilder()
            ->select('id')
            ->from('tmonier_webhook_delivery')
            ->where('id = :id')
            ->setParameter('id', min([\PHP_INT_MAX, ...$this->deliveryIdsOfTestEndpoint()]))
            ->forUpdate()
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
            self::assertNotContains(self::int($lockedId), $claims);
            self::assertNotEmpty(array_intersect($claims, $this->deliveryIdsOfTestEndpoint()), 'The other delivery of the test endpoint is claimed.');
        } finally {
            $this->observer->rollBack();
        }
    }

    /** Commits a customer: one pending customer.created delivery to the test endpoint. */
    private function committedDelivery(): int
    {
        $customer = $this->newCustomer();
        $this->emails[] = (string) $customer->getEmail();
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return self::int($this->observer->fetchOne(
            'SELECT d.id FROM tmonier_webhook_delivery d JOIN tmonier_webhook_outbox_event e ON e.id = d.event_id WHERE e.type = ? AND e.resource_id = ?',
            ['customer.created', (string) $this->idOf($customer)],
        ));
    }

    /** @return array<string, mixed> */
    private function deliveryRow(int $id): array
    {
        $row = $this->observer->fetchAssociative('SELECT * FROM tmonier_webhook_delivery WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }

    /** @param \Closure(WebhookRequest): DeliveryAttempt $send */
    private function dispatcherWith(\Closure $send): WebhookDispatcher
    {
        $container = self::getContainer();
        $sender = new class($send) implements WebhookSenderInterface {
            /** @param \Closure(WebhookRequest): DeliveryAttempt $send */
            public function __construct(private readonly \Closure $send)
            {
            }

            public function send(WebhookRequest $request): DeliveryAttempt
            {
                return ($this->send)($request);
            }
        };

        $doctrine = $container->get('doctrine');
        $claimer = $container->get('tmonier_webhook.delivery_claimer');
        $retryPolicy = $container->get('tmonier_webhook.retry_policy');
        $urlPolicy = $container->get('tmonier_webhook.url_policy');
        $redactor = $container->get('tmonier_webhook.url_redactor');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        self::assertInstanceOf(DeliveryClaimer::class, $claimer);
        self::assertInstanceOf(RetryPolicy::class, $retryPolicy);
        self::assertInstanceOf(EndpointUrlPolicy::class, $urlPolicy);
        self::assertInstanceOf(UrlRedactor::class, $redactor);

        return new WebhookDispatcher($doctrine, $claimer, $sender, new WebhookSigner(), $retryPolicy, $urlPolicy, $redactor, new NativeClock(), new NullLogger(), WebhookDelivery::class);
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
        return self::int($this->observer->fetchOne('SELECT COUNT(*) FROM tmonier_webhook_outbox_event WHERE customer_id = ?', [$customerId]));
    }

    /** @return list<int> */
    private function deliveryIdsOfTestEndpoint(): array
    {
        return array_map(self::int(...), $this->observer->fetchFirstColumn('SELECT id FROM tmonier_webhook_delivery WHERE endpoint_id = ?', [$this->endpointIds[0]]));
    }
}
