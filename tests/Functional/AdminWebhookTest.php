<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Functional;

use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\WebhookDispatcher;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Signature\WebhookSigner;

/**
 * Admin pages. Every test runs in a transaction rolled back at the end; the kernel is not
 * rebooted between requests so that the transaction spans them.
 */
final class AdminWebhookTest extends WebTestCase
{
    use WebhookTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->receiver()->reset();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testAdminAccessIsRequired(): void
    {
        $this->client->request('GET', '/admin/webhooks/endpoints/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));

        $this->client->request('POST', '/admin/webhooks/deliveries/bulk-replay');
        self::assertResponseRedirects();
        self::assertStringContainsString('/admin/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testCreationShowsTheSecretOnceAndNeverAgain(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/webhooks/endpoints/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="tmonier_webhook_endpoint[eventTypes][]"][value="order.placed"]');
        self::assertSelectorNotExists('input[name="tmonier_webhook_endpoint[eventTypes][]"][value="webhook.ping"]');
        self::assertSelectorNotExists('[name*="secret"]');

        $name = $this->unique('ERP');
        $form = $crawler->filter('form[name="tmonier_webhook_endpoint"]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['tmonier_webhook_endpoint']);
        $values['tmonier_webhook_endpoint']['name'] = $name;
        $values['tmonier_webhook_endpoint']['url'] = 'https://erp.example.com/webhooks/sylius?tenant=7';
        $values['tmonier_webhook_endpoint']['eventTypes'] = ['order.placed', 'order.paid'];
        $crawler = $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $secret = (string) $crawler->filter('[data-test-tmonier-webhook-secret]')->attr('value');
        self::assertMatchesRegularExpression('/^whsec_[A-Za-z0-9+\/]{43}=$/', $secret);

        $endpoint = $this->entityManager()->getRepository(WebhookEndpoint::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(WebhookEndpointInterface::class, $endpoint);
        self::assertSame($secret, $endpoint->getSecret());
        self::assertEqualsCanonicalizing(['order.placed', 'order.paid'], $endpoint->getEventTypes());

        foreach (['/admin/webhooks/endpoints/', sprintf('/admin/webhooks/endpoints/%d/edit', $this->idOf($endpoint))] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent(), $url . ' must not show the secret.');
            self::assertStringNotContainsString(substr($secret, 6, 12), (string) $this->client->getResponse()->getContent());
        }
        $grid = $this->client->request('GET', '/admin/webhooks/endpoints/')->html();
        self::assertStringContainsString('erp.example.com/webhooks/sylius', $grid);
        self::assertStringNotContainsString('tenant=7', $grid, 'Query strings are redacted in the grid.');
    }

    public function testPlainHttpUrlsAreRefused(): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/webhooks/endpoints/new');
        $form = $crawler->filter('form[name="tmonier_webhook_endpoint"]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['tmonier_webhook_endpoint']);
        $values['tmonier_webhook_endpoint']['name'] = 'Insecure';
        $values['tmonier_webhook_endpoint']['url'] = 'http://erp.example.com/hook';
        $values['tmonier_webhook_endpoint']['eventTypes'] = [];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('The endpoint URL must use https.', $html);
        self::assertStringContainsString('Choose at least one event.', $html);
    }

    public function testRegenerateSecretAndPing(): void
    {
        $this->loginAdmin();
        $endpoint = $this->createEndpoint(['order.placed']);
        $old = (string) $endpoint->getSecret();

        $crawler = $this->client->request('GET', sprintf('/admin/webhooks/endpoints/%d/edit', $this->idOf($endpoint)));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-test-tmonier-webhook-regenerate-secret]')->closest('form');
        self::assertNotNull($form);
        $crawler = $this->client->submit($form->form());
        self::assertResponseIsSuccessful();
        $secret = (string) $crawler->filter('[data-test-tmonier-webhook-secret]')->attr('value');
        self::assertNotSame($old, $secret);
        $endpoint = $this->reload($endpoint);
        self::assertSame($secret, $endpoint->getSecret());

        $crawler = $this->client->request('GET', sprintf('/admin/webhooks/endpoints/%d/edit', $this->idOf($endpoint)));
        $ping = $crawler->filter('[data-test-tmonier-webhook-ping]')->closest('form');
        self::assertNotNull($ping);
        $this->client->submit($ping->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame(['Ping queued: the worker (tmonier:webhook:dispatch) sends it.'], $this->flashes($crawler));

        $deliveries = $this->deliveriesOf($endpoint);
        self::assertCount(1, $deliveries);
        self::assertSame('webhook.ping', $deliveries[0]->getEvent()->getType());
        self::assertSame(1, $crawler->filter('[data-test-tmonier-webhook-status="pending"]')->count(), 'The grid is filtered on the endpoint.');

        // The ping is signed with the new secret.
        $this->dispatcher()->dispatchNext();
        $request = $this->receiver()->requests()[0];
        self::assertTrue((new WebhookSigner())->verify($request['headers']['webhook-id'], $request['headers']['webhook-timestamp'], $request['body'], $request['headers']['webhook-signature'], $secret, time()));
    }

    public function testInvalidCsrfTokenIsRefused(): void
    {
        $this->loginAdmin();
        $endpoint = $this->createEndpoint(['order.placed']);
        $old = $endpoint->getSecret();

        $this->client->request('POST', sprintf('/admin/webhooks/endpoints/%d/regenerate-secret', $this->idOf($endpoint)), ['_csrf_token' => 'forged']);

        self::assertResponseRedirects('/admin/webhooks/endpoints/');
        $endpoint = $this->reload($endpoint);
        self::assertSame($old, $endpoint->getSecret());
    }

    public function testDeliveryPageShowsRequestResponseAndAttempts(): void
    {
        $this->loginAdmin();
        $delivery = $this->deadDelivery();

        $crawler = $this->client->request('GET', sprintf('/admin/webhooks/deliveries/%d', $this->idOf($delivery)));

        self::assertResponseIsSuccessful();
        self::assertSame($delivery->getEvent()->getId(), trim($crawler->filter('[data-test-tmonier-webhook-id]')->text()));
        self::assertStringContainsString('"type": "customer.created"', $crawler->filter('[data-test-tmonier-webhook-request-body]')->text());
        self::assertStringContainsString('"error": "maintenance"', $crawler->filter('[data-test-tmonier-webhook-response-body]')->text());
        self::assertCount(2, $crawler->filter('[data-test-tmonier-webhook-attempts] tbody tr'));
        self::assertSelectorExists('[data-test-tmonier-webhook-replay]');
        self::assertSelectorNotExists('[data-test-tmonier-webhook-retry]');
    }

    public function testGridsAndDeadLetters(): void
    {
        $this->loginAdmin();
        $dead = $this->deadDelivery();
        $endpoint = $dead->getEndpoint();
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();

        $crawler = $this->client->request('GET', '/admin/webhooks/deliveries?criteria[endpoint]=' . $this->idOf($endpoint));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-test-tmonier-webhook-status="dead"]')->count());
        self::assertSame(1, $crawler->filter('[data-test-tmonier-webhook-status="pending"]')->count());

        $crawler = $this->client->request('GET', '/admin/webhooks/dead-letters?criteria[endpoint]=' . $this->idOf($endpoint));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('[data-test-tmonier-webhook-status="dead"]')->count());
        self::assertSame(0, $crawler->filter('[data-test-tmonier-webhook-status="pending"]')->count());

        $crawler = $this->client->request('GET', '/admin/webhooks/deliveries?criteria[webhookId][value]=' . $dead->getEvent()->getId());
        self::assertSame(1, $crawler->filter('[data-test-tmonier-webhook-status]')->count());

        $this->client->request('GET', '/admin/webhooks/endpoints/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', (string) $endpoint->getName());
    }

    public function testPagesHaveNoUntranslatedKey(): void
    {
        $this->loginAdmin();
        $delivery = $this->deadDelivery();
        $pages = [
            '/admin/webhooks/endpoints/',
            '/admin/webhooks/endpoints/new',
            sprintf('/admin/webhooks/endpoints/%d/edit', $this->idOf($delivery->getEndpoint())),
            '/admin/webhooks/deliveries',
            '/admin/webhooks/dead-letters',
            sprintf('/admin/webhooks/deliveries/%d', $this->idOf($delivery)),
        ];

        foreach ($pages as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            $text = $crawler->filter('body')->text();
            self::assertDoesNotMatchRegularExpression('/(tmonier_webhook|sylius)\.(ui|event_type)\.[a-z_.]+/', $text, $page);
        }
    }

    public function testRetryNowAndReplay(): void
    {
        $this->loginAdmin();
        $dead = $this->deadDelivery();

        // Replay: a new delivery of the same event (same webhook-id).
        $crawler = $this->client->request('GET', sprintf('/admin/webhooks/deliveries/%d', $this->idOf($dead)));
        $replayForm = $crawler->filter('[data-test-tmonier-webhook-replay]')->closest('form');
        self::assertNotNull($replayForm);
        $this->client->submit($replayForm->form());
        self::assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        self::assertSame(['Replay queued with the same webhook id.'], $this->flashes($crawler));
        $deliveries = $this->deliveriesOf($dead->getEndpoint());
        self::assertCount(2, $deliveries);
        $replay = $deliveries[1];
        self::assertSame($dead->getEvent()->getId(), $replay->getEvent()->getId());
        self::assertSame($dead->getId(), $replay->getReplayOf());
        self::assertSame(WebhookDeliveryInterface::STATUS_PENDING, $replay->getStatus());

        // Retry now: a failed delivery becomes due immediately.
        $this->receiver()->queue(new MockResponse('', ['http_code' => 500]));
        $this->dispatcher()->dispatchNext();
        $replay = $this->reload($replay);
        self::assertSame(WebhookDeliveryInterface::STATUS_FAILED, $replay->getStatus());
        self::assertFalse($replay->isDue(new \DateTimeImmutable()));

        $crawler = $this->client->request('GET', sprintf('/admin/webhooks/deliveries/%d', $this->idOf($replay)));
        $retryForm = $crawler->filter('[data-test-tmonier-webhook-retry]')->closest('form');
        self::assertNotNull($retryForm);
        $this->client->submit($retryForm->form());
        $crawler = $this->client->followRedirect();
        self::assertSame(['The delivery is due now: the worker sends it on its next run.'], $this->flashes($crawler));
        $replay = $this->reload($replay);
        self::assertTrue($replay->isDue(new \DateTimeImmutable('+1 second')));
    }

    public function testBulkReplaySkipsWhatCannotBeReplayed(): void
    {
        $this->loginAdmin();
        $dead = $this->deadDelivery();
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();
        $pending = $this->deliveriesOf($dead->getEndpoint())[1];

        $crawler = $this->client->request('GET', '/admin/webhooks/deliveries?criteria[endpoint]=' . $this->idOf($dead->getEndpoint()));
        $form = $crawler->filter('[data-test-tmonier-webhook-bulk-replay]')->closest('form');
        self::assertNotNull($form);
        $token = (string) $form->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/admin/webhooks/deliveries/bulk-replay', [
            '_csrf_token' => $token,
            'ids' => [(string) $dead->getId(), (string) $pending->getId()],
        ], [], ['HTTP_REFERER' => 'http://localhost/admin/webhooks/dead-letters']);

        self::assertResponseRedirects('/admin/webhooks/dead-letters');
        $crawler = $this->client->followRedirect();
        self::assertSame(['1 delivery(ies) queued for replay, 1 skipped (pending, failed or anonymized).'], $this->flashes($crawler));
        self::assertCount(3, $this->deliveriesOf($dead->getEndpoint()));
    }

    private function deadDelivery(): WebhookDeliveryInterface
    {
        $endpoint = $this->createEndpoint(['customer.created']);
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();
        $delivery = $this->deliveriesOf($endpoint)[0];

        $this->receiver()->queue(
            new MockResponse('{"error":"maintenance"}', ['http_code' => 503]),
            new MockResponse('{"error":"maintenance"}', ['http_code' => 503]),
        );
        $this->dispatcher()->dispatchNext();
        $this->entityManager()->refresh($delivery);
        $delivery->retryNow(new \DateTimeImmutable('-1 second'));
        $this->entityManager()->flush();
        $this->dispatcher()->dispatchNext();
        $this->entityManager()->refresh($delivery);
        $delivery->markDead();
        $this->entityManager()->flush();

        return $delivery;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function reload(object $entity): object
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find($entity::class, $this->idOf($entity));
        self::assertInstanceOf($entity::class, $reloaded);

        return $reloaded;
    }

    private function loginAdmin(): void
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');
    }

    /** @return list<string> */
    private function flashes(Crawler $crawler): array
    {
        /** @var list<string> $messages */
        $messages = $crawler->filter('[data-test-sylius-flash-message] .alert-description')->each(static fn (Crawler $node): string => trim($node->text()));

        return $messages;
    }

    private function dispatcher(): WebhookDispatcher
    {
        $dispatcher = self::getContainer()->get('tmonier_webhook.dispatcher');
        self::assertInstanceOf(WebhookDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
