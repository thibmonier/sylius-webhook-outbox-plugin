<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Payload;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

/**
 * Order DTO, version 1 (see README, "Payloads"). Amounts are integers in the minor unit of
 * currency_code. The order token and the customer IP are never exposed.
 */
final class OrderPayloadNormalizer
{
    public const VERSION = 1;

    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly CustomerPayloadNormalizer $customerNormalizer,
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(OrderInterface $order): array
    {
        $id = LazyValue::idOf($order);
        $customer = $order->getCustomer();

        return [
            'id' => $id,
            'number' => $order->getNumber(),
            'admin_url' => $this->adminUrlGenerator->generate('sylius_admin_order_show', ['id' => $id]),
            'channel' => $order->getChannel()?->getCode(),
            'currency_code' => $order->getCurrencyCode(),
            'locale_code' => $order->getLocaleCode(),
            'state' => $order->getState(),
            'checkout_state' => $order->getCheckoutState(),
            'payment_state' => $order->getPaymentState(),
            'shipping_state' => $order->getShippingState(),
            'checkout_completed_at' => Dates::format($order->getCheckoutCompletedAt()),
            'totals' => [
                'items' => $order->getItemsTotal(),
                'shipping' => $order->getShippingTotal(),
                'tax' => $order->getTaxTotal(),
                'promotion' => $order->getOrderPromotionTotal(),
                'total' => $order->getTotal(),
            ],
            'promotion_coupon' => $order->getPromotionCoupon()?->getCode(),
            'customer' => $this->customerNormalizer->summarize($customer instanceof CustomerInterface ? $customer : null),
            'billing_address' => self::address($order->getBillingAddress()),
            'shipping_address' => self::address($order->getShippingAddress()),
            'items' => array_values(array_map(self::item(...), $order->getItems()->toArray())),
            'payments' => array_values(array_map(self::payment(...), $order->getPayments()->toArray())),
            'shipments' => array_values(array_map(self::shipment(...), $order->getShipments()->toArray())),
            'notes' => $order->getNotes(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function address(?AddressInterface $address): ?array
    {
        if (null === $address) {
            return null;
        }

        return [
            'first_name' => $address->getFirstName(),
            'last_name' => $address->getLastName(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'postcode' => $address->getPostcode(),
            'country_code' => $address->getCountryCode(),
            'province_code' => $address->getProvinceCode(),
            'province_name' => $address->getProvinceName(),
            'phone_number' => $address->getPhoneNumber(),
        ];
    }

    /** @return array<string, mixed> */
    private static function item(mixed $item): array
    {
        \assert($item instanceof OrderItemInterface);

        return [
            'id' => LazyValue::idOf($item),
            'variant_code' => $item->getVariant()?->getCode(),
            'product_code' => $item->getProduct()?->getCode(),
            'product_name' => $item->getProductName(),
            'variant_name' => $item->getVariantName(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPrice(),
            'total' => $item->getTotal(),
        ];
    }

    /** @return array<string, mixed> */
    private static function payment(mixed $payment): array
    {
        \assert($payment instanceof PaymentInterface);

        return [
            'id' => LazyValue::idOf($payment),
            'method_code' => $payment->getMethod()?->getCode(),
            'state' => $payment->getState(),
            'amount' => $payment->getAmount(),
            'currency_code' => $payment->getCurrencyCode(),
        ];
    }

    /** @return array<string, mixed> */
    private static function shipment(mixed $shipment): array
    {
        \assert($shipment instanceof ShipmentInterface);

        return [
            'id' => LazyValue::idOf($shipment),
            'method_code' => $shipment->getMethod()?->getCode(),
            'state' => $shipment->getState(),
            'tracking' => $shipment->getTracking(),
            'shipped_at' => Dates::format($shipment->getShippedAt()),
        ];
    }
}
