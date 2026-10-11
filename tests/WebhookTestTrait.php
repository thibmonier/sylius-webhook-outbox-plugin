<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\Tmonier\SyliusWebhookOutboxPlugin\Http\WebhookReceiverStub;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\OutboxEventInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDelivery;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookDeliveryInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpoint;
use Tmonier\SyliusWebhookOutboxPlugin\Entity\WebhookEndpointInterface;

/**
 * Helpers of the integration and functional tests. They run against the test application with
 * the Sylius fixtures, which are random: every test creates the customers, orders and endpoints
 * it needs, in the state it needs.
 */
trait WebhookTestTrait
{
    private static int $sequence = 0;

    private function beginTestTransaction(): void
    {
        $this->connection()->beginTransaction();
    }

    private function rollBackTestTransaction(): void
    {
        while ($this->connection()->isTransactionActive()) {
            $this->connection()->rollBack();
        }
    }

    /** Unique marker for e-mails, numbers and names created by a test. */
    private function unique(string $prefix): string
    {
        return sprintf('%s-%s-%d', $prefix, bin2hex(random_bytes(4)), ++self::$sequence);
    }

    /**
     * @param list<string>|null $eventTypes null: every subscribable type
     * @param list<string> $channelCodes
     */
    private function createEndpoint(?array $eventTypes = null, array $channelCodes = [], string $url = 'https://93.184.215.14/webhooks', bool $flush = true): WebhookEndpointInterface
    {
        $endpoint = new WebhookEndpoint();
        $endpoint->setName($this->unique('Endpoint'));
        $endpoint->setUrl($url);
        $endpoint->setSecret('whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw');
        $endpoint->setEventTypes($eventTypes ?? [
            'customer.created',
            'customer.updated',
            'order.cancelled',
            'order.paid',
            'order.placed',
            'order.shipped',
            'product_variant.stock_changed',
        ]);
        $endpoint->setChannelCodes($channelCodes);
        $this->entityManager()->persist($endpoint);
        if ($flush) {
            $this->entityManager()->flush();
        }

        return $endpoint;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->entityManager()->getRepository(ChannelInterface::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(ChannelInterface::class, $channel, 'The fixtures contain a channel.');

        return $channel;
    }

    private function newCustomer(?string $email = null): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.customer');
        $customer = $factory->createNew();
        $customer->setEmail($email ?? $this->unique('webhook') . '@example.com');
        $customer->setFirstName('Ada');
        $customer->setLastName('Lovelace');
        $customer->setPhoneNumber('+33 1 23 45 67 89');

        return $customer;
    }

    /**
     * A completed order with no item, created from scratch: the fixtures' orders are in random states.
     */
    private function newCompletedOrder(CustomerInterface $customer, ?ChannelInterface $channel = null): OrderInterface
    {
        /** @var FactoryInterface<OrderInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.order');
        $order = $factory->createNew();
        $channel ??= $this->channel();
        $order->setChannel($channel);
        $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        $order->setLocaleCode((string) $channel->getDefaultLocale()?->getCode());
        $order->setCustomer($customer);
        $order->setNumber($this->unique('WH'));
        $order->setState(OrderInterface::STATE_NEW);
        $order->setCheckoutState(OrderCheckoutStates::STATE_COMPLETED);
        $order->setPaymentState(OrderPaymentStates::STATE_AWAITING_PAYMENT);
        $order->setShippingState(OrderShippingStates::STATE_READY);
        $order->setCheckoutCompletedAt(new \DateTime());

        return $order;
    }

    private function trackedVariant(): ProductVariantInterface
    {
        $variant = $this->entityManager()->getRepository(ProductVariantInterface::class)->findOneBy([], ['id' => 'ASC']);
        self::assertInstanceOf(ProductVariantInterface::class, $variant, 'The fixtures contain variants.');

        return $variant;
    }

    /** @return list<OutboxEventInterface> */
    private function eventsOfType(string $type, ?string $resourceId = null): array
    {
        $criteria = ['type' => $type];
        if (null !== $resourceId) {
            $criteria['resourceId'] = $resourceId;
        }

        /** @var list<OutboxEventInterface> $events */
        $events = $this->entityManager()->getRepository(OutboxEvent::class)->findBy($criteria);

        return $events;
    }

    /** @return list<WebhookDeliveryInterface> */
    private function deliveriesOf(WebhookEndpointInterface $endpoint): array
    {
        /** @var list<WebhookDeliveryInterface> $deliveries */
        $deliveries = $this->entityManager()->getRepository(WebhookDelivery::class)->findBy(['endpoint' => $endpoint], ['id' => 'ASC']);

        return $deliveries;
    }

    private function receiver(): WebhookReceiverStub
    {
        $receiver = self::getContainer()->get(WebhookReceiverStub::class);
        self::assertInstanceOf(WebhookReceiverStub::class, $receiver);

        return $receiver;
    }

    private function idOf(object $resource): int
    {
        self::assertTrue(method_exists($resource, 'getId'));
        $id = $resource->getId();
        self::assertIsInt($id);

        return $id;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
