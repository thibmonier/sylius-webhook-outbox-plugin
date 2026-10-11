<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Command\DispatchWebhooksCommand;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\DeliveryClaimer;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\RetryPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookRequest;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookSenderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDelivery;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Http\EndpointUrlPolicy;
use Tmonier\SyliusWebhookOutboxPlugin\Http\UrlRedactor;
use Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

/**
 * The worker against the offline receiver of the test application (MockHttpClient behind the
 * real SSRF guard). Each test runs in a transaction rolled back at the end.
 */
final class DispatchTest extends KernelTestCase
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

    public function testDeliversASignedStandardWebhooksRequest(): void
    {
        [$endpoint, $delivery] = $this->pendingDelivery();
        $before = time();

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame($delivery->getId(), $processed->getId());
        self::assertSame(WebhookDeliveryInterface::STATUS_DELIVERED, $processed->getStatus());
        self::assertSame(200, $processed->getLastResponseStatus());
        self::assertSame('{"ok":true}', $processed->getLastResponseBody());
        self::assertNotNull($processed->getDeliveredAt());

        $requests = $this->receiver()->requests();
        self::assertCount(1, $requests);
        $request = $requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://93.184.215.14/webhooks', $request['url']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame($delivery->getEvent()->getId(), $request['headers']['webhook-id']);
        self::assertGreaterThanOrEqual($before, (int) $request['headers']['webhook-timestamp']);
        self::assertTrue((new WebhookSigner())->verify(
            $request['headers']['webhook-id'],
            $request['headers']['webhook-timestamp'],
            $request['body'],
            $request['headers']['webhook-signature'],
            (string) $endpoint->getSecret(),
            time(),
        ));

        $body = json_decode($request['body'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['id', 'type', 'version', 'timestamp', 'channel', 'data'], array_keys($body));
        self::assertSame('customer.created', $body['type']);
        self::assertSame(1, $body['version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', self::string($body['timestamp']));

        self::assertNull($this->dispatcher()->dispatchNext(), 'Nothing left to send.');
    }

    public function testServerErrorSchedulesTheNextAttemptOfTheSchedule(): void
    {
        [, $delivery] = $this->pendingDelivery();
        $this->receiver()->queue(new MockResponse('Oops', ['http_code' => 500]));

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_FAILED, $processed->getStatus());
        self::assertSame(1, $processed->getAttempts());
        self::assertSame(500, $processed->getLastResponseStatus());
        $delay = $processed->getNextAttemptAt()?->getTimestamp() - $processed->getLastAttemptAt()?->getTimestamp();
        self::assertSame(5, $delay, 'Second attempt 5 seconds later (no jitter in the test application).');
        self::assertCount(1, $processed->getAttemptHistory());
        self::assertNull($this->dispatcher()->dispatchNext(), 'Not due yet.');
        self::assertSame($delivery->getId(), $processed->getId());
    }

    public function testRetryAfterIsHonouredOn429(): void
    {
        $this->pendingDelivery();
        $this->receiver()->queue(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '120']]));

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(120, $processed->getNextAttemptAt()?->getTimestamp() - $processed->getLastAttemptAt()?->getTimestamp());
    }

    public function testGoneDisablesTheEndpointAndKillsTheDelivery(): void
    {
        [$endpoint] = $this->pendingDelivery();
        $this->receiver()->queue(new MockResponse('', ['http_code' => 410]));

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_DEAD, $processed->getStatus());
        $this->entityManager()->refresh($endpoint);
        self::assertFalse($endpoint->isEnabled());
        self::assertSame('410 Gone', $endpoint->getDisabledReason());
    }

    public function testGoneSuspendsTheOtherDeliveriesOfTheEndpoint(): void
    {
        [$endpoint] = $this->pendingDelivery();
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();
        self::assertCount(3, $this->deliveriesOf($endpoint));
        $this->receiver()->queue(new MockResponse('', ['http_code' => 410]));

        self::assertNotNull($this->dispatcher()->dispatchNext());
        self::assertNull($this->dispatcher()->dispatchNext(), 'Nothing left to send to a gone endpoint.');

        $this->entityManager()->clear();
        self::assertSame(
            ['dead', 'suspended', 'suspended'],
            array_map(static fn (WebhookDeliveryInterface $delivery): string => $delivery->getStatus(), $this->deliveriesOf($endpoint)),
        );
        self::assertCount(1, $this->receiver()->requests());
    }

    public function testOpenDeliveriesOfADisabledEndpointAreSuspendedByTheWorker(): void
    {
        // A checkout whose snapshot predates the disable fanned out to the endpoint anyway:
        // the endpoint is disabled behind the ORM (no listener), the delivery stays pending.
        [$endpoint, $delivery] = $this->pendingDelivery();
        $this->connection()->update('tmonier_webhook_endpoint', ['enabled' => 0], ['id' => $endpoint->getId()]);
        $this->connection()->executeStatement('UPDATE tmonier_webhook_outbox_event SET occurred_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $delivery->getEvent()->getId()]);

        $events = self::getContainer()->get('tmonier_webhook.repository.outbox_event');
        self::assertInstanceOf(OutboxEventRepositoryInterface::class, $events);
        self::assertSame(1, $events->countPurgeableBefore(new \DateTimeImmutable('-1 day')), 'Does not block the purge.');

        self::assertNull($this->dispatcher()->dispatchNext());
        self::assertSame('suspended', $this->connection()->fetchOne('SELECT status FROM tmonier_webhook_delivery WHERE id = ?', [$delivery->getId()]));
    }

    public function testLogsAndErrorsNeverContainTheUrlCredentials(): void
    {
        $this->pendingDelivery('https://93.184.215.14/services/T0123ABCD/B0456EFGH/aBcDeFgHiJkLmNoPqRsTuVwX?token=s3cr3t');
        $this->receiver()->queue(new MockResponse('', ['error' => 'Idle timeout reached for "https://93.184.215.14/services/T0123ABCD/B0456EFGH/aBcDeFgHiJkLmNoPqRsTuVwX?token=s3cr3t".']));
        $logger = new class() extends AbstractLogger {
            public string $written = '';

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                unset($context['exception']);
                $this->written .= $message . ' ' . json_encode($context, \JSON_UNESCAPED_SLASHES) . "\n";
            }
        };
        $container = self::getContainer();
        $doctrine = $container->get('doctrine');
        $claimer = $container->get('tmonier_webhook.delivery_claimer');
        $sender = $container->get('tmonier_webhook.sender');
        $retryPolicy = $container->get('tmonier_webhook.retry_policy');
        $urlPolicy = $container->get('tmonier_webhook.url_policy');
        $redactor = $container->get('tmonier_webhook.url_redactor');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        self::assertInstanceOf(DeliveryClaimer::class, $claimer);
        self::assertInstanceOf(WebhookSenderInterface::class, $sender);
        self::assertInstanceOf(RetryPolicy::class, $retryPolicy);
        self::assertInstanceOf(EndpointUrlPolicy::class, $urlPolicy);
        self::assertInstanceOf(UrlRedactor::class, $redactor);
        $dispatcher = new WebhookDispatcher($doctrine, $claimer, $sender, new WebhookSigner(), $retryPolicy, $urlPolicy, $redactor, new NativeClock(), $logger, WebhookDelivery::class);

        $processed = $dispatcher->dispatchNext();

        self::assertNotNull($processed);
        self::assertStringContainsString('93.184.215.14/services/', $logger->written);
        foreach (['T0123ABCD', 'B0456EFGH', 'aBcDeFgHiJkLmNoPqRsTuVwX', 's3cr3t'] as $secret) {
            self::assertStringNotContainsString($secret, $logger->written, 'Log context');
            self::assertStringNotContainsString($secret, (string) $processed->getLastError(), 'Recorded error');
        }
    }

    public function testNetworkErrorsAreRetriedWithoutLeakingTheQueryString(): void
    {
        $this->pendingDelivery('https://93.184.215.14/hook?token=s3cr3t');
        $this->receiver()->queue(new MockResponse('', ['error' => 'Idle timeout reached for "https://93.184.215.14/hook?token=s3cr3t".']));

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_FAILED, $processed->getStatus());
        self::assertNull($processed->getLastResponseStatus());
        self::assertStringContainsString('Idle timeout', (string) $processed->getLastError());
        self::assertStringNotContainsString('s3cr3t', (string) $processed->getLastError());
    }

    /** @return iterable<string, array{string}> */
    public static function privateUrls(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/webhooks'];
        yield 'cloud metadata' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'private network' => ['https://10.0.0.12/erp'];
    }

    /** @dataProvider privateUrls */
    public function testPrivateNetworksAreBlocked(string $url): void
    {
        $this->pendingDelivery($url);

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_FAILED, $processed->getStatus());
        self::assertSame([], $this->receiver()->requests(), 'The request never left the guard.');
        self::assertStringContainsString('is blocked for', (string) $processed->getLastError());
    }

    public function testPlainHttpIsRejectedWithoutRetry(): void
    {
        $this->pendingDelivery('http://93.184.215.14/webhooks');

        $processed = $this->dispatcher()->dispatchNext();

        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_DEAD, $processed->getStatus());
        self::assertSame([], $this->receiver()->requests());
    }

    public function testDeliveryIsDeadAfterTheLastAttemptOfTheSchedule(): void
    {
        $this->pendingDelivery();
        $processed = null;
        for ($i = 0; $i < 10; ++$i) {
            $this->receiver()->queue(new MockResponse('', ['http_code' => 503]));
            $processed = $this->dispatcher()->dispatchNext();
            self::assertNotNull($processed);
            $processed->retryNow(new \DateTimeImmutable('-1 second'));
            $this->entityManager()->flush();
        }

        self::assertSame(WebhookDeliveryInterface::STATUS_DEAD, $processed->getStatus());
        self::assertSame(10, $processed->getAttempts());
        self::assertCount(WebhookDeliveryInterface::HISTORY_SIZE, $processed->getAttemptHistory());
        self::assertNull($this->dispatcher()->dispatchNext());
    }

    public function testExpiredLeasesAreClaimedAgainButLiveLeasesAreNot(): void
    {
        [, $delivery] = $this->pendingDelivery();
        $table = 'tmonier_webhook_delivery';

        $this->connection()->update($table, ['claimed_until' => (new \DateTimeImmutable('+5 minutes', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'), 'claim_token' => 'other-worker'], ['id' => $delivery->getId()]);
        self::assertNull($this->dispatcher()->dispatchNext(), 'Claimed by a live worker.');

        // The other worker crashed: its lease expired.
        $this->connection()->update($table, ['claimed_until' => (new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')], ['id' => $delivery->getId()]);
        $dispatcher = $this->dispatcher();
        $processed = $dispatcher->dispatchNext();
        self::assertNotNull($processed);
        self::assertSame(WebhookDeliveryInterface::STATUS_DELIVERED, $processed->getStatus());
        self::assertNull($this->connection()->fetchOne('SELECT claim_token FROM tmonier_webhook_delivery WHERE id = ?', [$delivery->getId()]));
    }

    public function testDisabledEndpointsAreNotDispatched(): void
    {
        [$endpoint] = $this->pendingDelivery();
        $endpoint->setEnabled(false);
        $this->entityManager()->flush();

        self::assertNull($this->dispatcher()->dispatchNext());
    }

    public function testCommandSendsWhatIsDueAndExits(): void
    {
        $this->pendingDelivery();
        $this->pendingDelivery();
        $this->receiver()->queue(new MockResponse('', ['http_code' => 500]));

        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('tmonier:webhook:dispatch'));
        $tester->execute(['--time-limit' => '120']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('3 attempt(s): 2 delivered, 1 to retry, 0 dead.', $tester->getDisplay());
    }

    public function testTimeLimitMustLeaveRoomForOneRequest(): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('tmonier:webhook:dispatch'));

        self::assertSame(2, $tester->execute(['--time-limit' => '30']));
        self::assertStringContainsString('--time-limit must be greater than max_duration (30 s)', $tester->getDisplay());
    }

    public function testTheRunStopsClaimingWhenARequestCouldOutliveTheTimeLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->pendingDelivery();
        }
        $sent = 0;
        $command = $this->commandWith(1.0, static function () use (&$sent): void {
            ++$sent;
            usleep(450_000);
        });

        $start = microtime(true);
        $tester = new CommandTester($command);
        $tester->execute(['--time-limit' => '2']);
        $elapsed = microtime(true) - $start;

        $tester->assertCommandIsSuccessful();
        self::assertGreaterThanOrEqual(1, $sent);
        self::assertLessThan(5, $sent, 'No claim during the last max_duration seconds.');
        self::assertLessThan(2.0, $elapsed, 'The last request ends before the time limit.');
    }

    public function testTheSmallestTimeLimitStillSends(): void
    {
        $this->pendingDelivery();
        $this->pendingDelivery();
        $sent = 0;
        $tester = new CommandTester($this->commandWith(1.0, static function () use (&$sent): void {
            ++$sent;
        }));

        $tester->execute(['--time-limit' => '2']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(3, $sent, 'Every due delivery (the first endpoint also got the second event).');
    }

    /** @param \Closure(): void $onSend */
    private function commandWith(float $maxDuration, \Closure $onSend): DispatchWebhooksCommand
    {
        $container = self::getContainer();
        $sender = new class($onSend) implements WebhookSenderInterface {
            /** @param \Closure(): void $onSend */
            public function __construct(private readonly \Closure $onSend)
            {
            }

            public function send(WebhookRequest $request): DeliveryAttempt
            {
                ($this->onSend)();

                return new DeliveryAttempt(new \DateTimeImmutable(), 1, 204);
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
        $dispatcher = new WebhookDispatcher($doctrine, $claimer, $sender, new WebhookSigner(), $retryPolicy, $urlPolicy, $redactor, new NativeClock(), new NullLogger(), WebhookDelivery::class);

        return new DispatchWebhooksCommand($dispatcher, new NullLogger(), $maxDuration);
    }

    /** @return array{WebhookEndpointInterface, WebhookDeliveryInterface} */
    private function pendingDelivery(string $url = 'https://93.184.215.14/webhooks'): array
    {
        $endpoint = $this->createEndpoint(['customer.created'], [], $url);
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();

        $deliveries = $this->deliveriesOf($endpoint);
        self::assertCount(1, $deliveries);

        return [$endpoint, $deliveries[0]];
    }

    private function dispatcher(): WebhookDispatcher
    {
        $dispatcher = self::getContainer()->get('tmonier_webhook.dispatcher');
        self::assertInstanceOf(WebhookDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
