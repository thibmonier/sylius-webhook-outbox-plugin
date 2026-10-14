<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\EntityGuard;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\OrderPayloadNormalizer;

/**
 * order.placed (checkout completed), order.paid, order.cancelled and order.shipped, from the
 * state columns of the order. Works whatever the entry point (shop, API, admin, CLI), as long as
 * the change is flushed through Doctrine.
 */
final readonly class OrderEventDetector implements ChangeDetectorInterface
{
    /** Event type => [order field, target state]. Order matters: placed before paid. */
    private const TRANSITIONS = [
        CoreEventTypeProvider::ORDER_PLACED => ['checkoutState', OrderCheckoutStates::STATE_COMPLETED],
        CoreEventTypeProvider::ORDER_PAID => ['paymentState', OrderPaymentStates::STATE_PAID],
        CoreEventTypeProvider::ORDER_SHIPPED => ['shippingState', OrderShippingStates::STATE_SHIPPED],
        CoreEventTypeProvider::ORDER_CANCELLED => ['state', OrderInterface::STATE_CANCELLED],
    ];

    public function __construct(
        private OrderPayloadNormalizer $normalizer,
        private EntityGuard $guard,
    ) {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        foreach ($unitOfWork->getScheduledEntityInsertions() as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            // Orders created directly in their final state (admin order creation, imports).
            $reached = [];
            foreach (self::TRANSITIONS as $type => [$field, $state]) {
                if (CoreEventTypeProvider::ORDER_CANCELLED !== $type && $state === $this->current($order, $field)) {
                    $reached[] = $type;
                }
            }
            if (in_array(CoreEventTypeProvider::ORDER_PLACED, $reached, true)) {
                yield from $this->guard->events($order, fn (): iterable => $this->events($order, $reached));
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $order) {
            if (!$order instanceof OrderInterface) {
                continue;
            }
            $changeSet = $unitOfWork->getEntityChangeSet($order);
            $reached = [];
            foreach (self::TRANSITIONS as $type => [$field, $state]) {
                if (isset($changeSet[$field]) && $state === $changeSet[$field][1] && $state !== $changeSet[$field][0]) {
                    $reached[] = $type;
                }
            }
            if ([] !== $reached) {
                yield from $this->guard->events($order, fn (): iterable => $this->events($order, $reached));
            }
        }
    }

    /**
     * @param list<string> $types
     *
     * @return iterable<WebhookEvent>
     */
    private function events(OrderInterface $order, array $types): iterable
    {
        if ([] === $types) {
            return;
        }

        $data = $this->normalizer->normalize($order);
        $customer = $order->getCustomer();
        foreach ($types as $type) {
            yield new WebhookEvent(
                $type,
                $data,
                $order->getChannel()?->getCode(),
                'order',
                LazyValue::idOf($order),
                $customer instanceof \Sylius\Component\Customer\Model\CustomerInterface ? LazyValue::idOf($customer) : null,
            );
        }
    }

    private function current(OrderInterface $order, string $field): ?string
    {
        return match ($field) {
            'checkoutState' => $order->getCheckoutState(),
            'paymentState' => $order->getPaymentState(),
            'shippingState' => $order->getShippingState(),
            default => $order->getState(),
        };
    }
}
