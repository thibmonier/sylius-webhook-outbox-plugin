<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderShippingStates;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\WebhookTestTrait;
use Tmonier\SyliusWebhookOutboxPlugin\Delivery\EnvelopeFactory;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\OutboxRecorderInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;

/**
 * What is captured, for whom, with which payload. Each test runs in a transaction rolled back
 * at the end (the commit / rollback guarantee itself is covered by TransactionalOutboxTest).
 */
final class CaptureTest extends KernelTestCase
{
    use WebhookTestTrait;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    public function testNothingIsRecordedWithoutSubscribedEndpoint(): void
    {
        $this->createEndpoint(['order.paid']);
        $customer = $this->newCustomer();
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        self::assertSame([], $this->eventsOfType('customer.created', (string) $this->idOf($customer)));
    }

    public function testOneDeliveryPerSubscribedEndpointAndChannelFilter(): void
    {
        $channel = $this->channel();
        $all = $this->createEndpoint(['order.placed']);
        $sameChannel = $this->createEndpoint(['order.placed'], [(string) $channel->getCode()]);
        $otherChannel = $this->createEndpoint(['order.placed'], ['NOT_THIS_CHANNEL']);
        $disabled = $this->createEndpoint(['order.placed']);
        $disabled->setEnabled(false);
        $this->entityManager()->flush();

        $order = $this->newCompletedOrder($this->newCustomer(), $channel);
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();

        $events = $this->eventsOfType('order.placed', (string) $this->idOf($order));
        self::assertCount(1, $events);
        self::assertSame($channel->getCode(), $events[0]->getChannelCode());
        self::assertCount(1, $this->deliveriesOf($all));
        self::assertCount(1, $this->deliveriesOf($sameChannel));
        self::assertCount(0, $this->deliveriesOf($otherChannel));
        self::assertCount(0, $this->deliveriesOf($disabled));
        self::assertSame(WebhookDeliveryInterface::STATUS_PENDING, $this->deliveriesOf($all)[0]->getStatus());
    }

    public function testOrderStateTransitions(): void
    {
        $this->createEndpoint();
        $order = $this->newCompletedOrder($this->newCustomer());
        $this->entityManager()->persist($order);
        $this->entityManager()->flush();
        $id = (string) $this->idOf($order);

        $order->setShippingState(OrderShippingStates::STATE_SHIPPED);
        $this->entityManager()->flush();
        $order->setState(OrderInterface::STATE_CANCELLED);
        $this->entityManager()->flush();
        $order->setNotes('No transition: no event');
        $this->entityManager()->flush();

        self::assertCount(1, $this->eventsOfType('order.placed', $id));
        self::assertCount(1, $this->eventsOfType('order.shipped', $id));
        self::assertCount(1, $this->eventsOfType('order.cancelled', $id));
        self::assertCount(0, $this->eventsOfType('order.paid', $id));

        $cancelled = $this->eventsOfType('order.cancelled', $id)[0]->getPayload();
        self::assertSame('cancelled', $cancelled['state']);
        self::assertSame('shipped', $cancelled['shipping_state']);
    }

    public function testCustomerUpdatedOnlyForWhitelistedFields(): void
    {
        $this->createEndpoint(['customer.updated']);
        $customer = $this->newCustomer();
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();
        $id = (string) $this->idOf($customer);

        $customer->setFirstName('Augusta');
        $customer->setLastName('King');
        $this->entityManager()->flush();
        $customer->setEmailCanonical('not-whitelisted@example.com');
        $this->entityManager()->flush();

        $events = $this->eventsOfType('customer.updated', $id);
        self::assertCount(1, $events);
        $payload = $events[0]->getPayload();
        self::assertSame(['first_name', 'last_name'], $payload['changed_fields']);
        self::assertSame('Augusta', $payload['first_name']);
        self::assertSame((int) $id, $events[0]->getCustomerId());
    }

    public function testCustomerPayloadNeverExposesAccountSecrets(): void
    {
        $this->createEndpoint(['customer.created']);
        $customer = $this->newCustomer();
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        $json = EnvelopeFactory::encode($this->eventsOfType('customer.created', (string) $this->idOf($customer))[0]);
        foreach (['password', 'token', 'salt', 'roles'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $json);
        }
    }

    public function testStockChangesAreCoalescedPerFlush(): void
    {
        $this->createEndpoint(['product_variant.stock_changed']);
        $variants = $this->entityManager()->getRepository(\Sylius\Component\Core\Model\ProductVariantInterface::class)->findBy([], ['id' => 'ASC'], 3);
        self::assertCount(3, $variants);

        foreach ($variants as $variant) {
            $variant->setOnHand((int) $variant->getOnHand() + 7);
            $variant->setOnHold((int) $variant->getOnHold() + 1);
        }
        $this->entityManager()->flush();

        $events = $this->eventsOfType('product_variant.stock_changed');
        self::assertCount(1, $events, 'One event for the whole flush.');
        $lines = $events[0]->getPayload()['variants'];
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        self::assertIsArray($lines[0]);
        self::assertSame($variants[0]->getCode(), $lines[0]['code']);
        self::assertSame((int) $variants[0]->getOnHand(), $lines[0]['on_hand']);
        self::assertIsArray($lines[0]['previous']);
        self::assertSame((int) $variants[0]->getOnHand() - 7, $lines[0]['previous']['on_hand']);
    }

    public function testTypesDisabledInConfigurationAreNotRecordedButPingsAreTargeted(): void
    {
        $endpoint = $this->createEndpoint(['order.placed']);
        $recorder = self::getContainer()->get(OutboxRecorderInterface::class);
        self::assertInstanceOf(OutboxRecorderInterface::class, $recorder);

        $ping = $recorder->record(new WebhookEvent('webhook.ping', ['endpoint_id' => $endpoint->getId()], null, 'webhook_endpoint', $endpoint->getId()), $endpoint);
        $this->entityManager()->flush();

        self::assertNotNull($ping);
        self::assertCount(1, $this->deliveriesOf($endpoint));
        self::assertSame((string) $this->idOf($endpoint), $ping->getResourceId());
        self::assertSame(1, $ping->getVersion());
    }

    public function testPublicRecorderApiWritesWithTheCallersFlush(): void
    {
        $endpoint = $this->createEndpoint(['order.paid']);
        $recorder = self::getContainer()->get(OutboxRecorderInterface::class);
        self::assertInstanceOf(OutboxRecorderInterface::class, $recorder);

        $event = $recorder->record(new WebhookEvent('order.paid', ['id' => 1]));
        self::assertNotNull($event);
        self::assertSame([], $this->eventsOfType('order.paid', null), 'Not written before the flush.');

        $this->entityManager()->flush();
        self::assertCount(1, $this->deliveriesOf($endpoint));
    }

    public function testNewTrackedVariantsPublishTheirInitialStock(): void
    {
        $this->createEndpoint(['product_variant.stock_changed']);
        /** @var \Sylius\Resource\Factory\FactoryInterface<\Sylius\Component\Core\Model\ProductVariantInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.product_variant');
        $variant = $factory->createNew();
        $variant->setCode($this->unique('VARIANT'));
        $variant->setProduct($this->trackedVariant()->getProduct());
        $variant->setTracked(true);
        $variant->setOnHand(12);
        $this->entityManager()->persist($variant);
        $this->entityManager()->flush();

        $events = $this->eventsOfType('product_variant.stock_changed');
        self::assertCount(1, $events);
        $lines = $events[0]->getPayload()['variants'];
        self::assertIsArray($lines);
        self::assertSame([[
            'id' => $this->idOf($variant),
            'on_hand' => 12,
            'previous' => ['on_hand' => null, 'on_hold' => null],
        ]], array_map(static fn (mixed $line): array => is_array($line) ? ['id' => $line['id'], 'on_hand' => $line['on_hand'], 'previous' => $line['previous']] : [], $lines));
    }

    public function testAnExistingCustomerSigningUpGivesCustomerUpdated(): void
    {
        $this->createEndpoint(['customer.updated']);
        $customer = $this->newCustomer();
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        /** @var \Sylius\Resource\Factory\FactoryInterface<\Sylius\Component\Core\Model\ShopUserInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.shop_user');
        $user = $factory->createNew();
        $user->setCustomer($customer);
        $user->setPlainPassword('sylius-webhook');
        $user->setEnabled(true);
        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        $events = $this->eventsOfType('customer.updated', (string) $this->idOf($customer));
        self::assertCount(1, $events);
        self::assertSame(['has_account'], $events[0]->getPayload()['changed_fields']);
        self::assertTrue($events[0]->getPayload()['has_account']);
    }

    public function testEventIdsIncreaseWithinAFlush(): void
    {
        $this->createEndpoint(['customer.created']);
        $customers = [];
        for ($i = 0; $i < 20; ++$i) {
            $customers[] = $customer = $this->newCustomer();
            $this->entityManager()->persist($customer);
        }
        $this->entityManager()->flush();

        $ids = [];
        foreach ($customers as $customer) {
            $ids[] = $this->eventsOfType('customer.created', (string) $this->idOf($customer))[0]->getId();
        }
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'UUIDv7 ids follow the recording order (receivers sort by id).');
        self::assertCount(20, array_unique($ids));
    }

    public function testDisablingAnEndpointSuspendsItsOpenDeliveries(): void
    {
        $endpoint = $this->createEndpoint(['customer.created']);
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->persist($this->newCustomer());
        $this->entityManager()->flush();
        [$delivered, $open] = $this->deliveriesOf($endpoint);
        $delivered->markDelivered(new \DateTimeImmutable());
        $this->entityManager()->flush();

        $endpoint->disable('410 Gone', new \DateTimeImmutable());
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        self::assertSame(
            ['delivered', 'suspended'],
            array_map(static fn (WebhookDeliveryInterface $delivery): string => $delivery->getStatus(), $this->deliveriesOf($endpoint)),
        );

        // Suspended deliveries do not block the purge.
        $this->connection()->executeStatement('UPDATE tmonier_webhook_outbox_event SET occurred_at = ? WHERE id = ?', ['2020-01-01 00:00:00', $open->getEvent()->getId()]);
        $repository = self::getContainer()->get('tmonier_webhook.repository.outbox_event');
        self::assertInstanceOf(\Tmonier\SyliusWebhookOutboxPlugin\Repository\OutboxEventRepositoryInterface::class, $repository);
        self::assertSame(1, $repository->countPurgeableBefore(new \DateTimeImmutable('-1 day')));

        $endpoint = $this->entityManager()->find($endpoint::class, $this->idOf($endpoint));
        self::assertInstanceOf(\Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface::class, $endpoint);
        $endpoint->setEnabled(true);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $deliveries = $this->deliveriesOf($endpoint);
        self::assertSame('pending', $deliveries[1]->getStatus(), 'Requeued when the endpoint is enabled again.');
        self::assertTrue($deliveries[1]->isDue(new \DateTimeImmutable('+1 second')));
    }
}
