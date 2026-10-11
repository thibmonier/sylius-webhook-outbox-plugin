<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\EventType;

final class CoreEventTypeProvider implements EventTypeProviderInterface
{
    public const ORDER_PLACED = 'order.placed';

    public const ORDER_PAID = 'order.paid';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const ORDER_SHIPPED = 'order.shipped';

    public const CUSTOMER_CREATED = 'customer.created';

    public const CUSTOMER_UPDATED = 'customer.updated';

    public const PRODUCT_VARIANT_STOCK_CHANGED = 'product_variant.stock_changed';

    public const WEBHOOK_PING = 'webhook.ping';

    public function getEventTypes(): iterable
    {
        yield new EventType(self::ORDER_PLACED);
        yield new EventType(self::ORDER_PAID);
        yield new EventType(self::ORDER_CANCELLED);
        yield new EventType(self::ORDER_SHIPPED);
        yield new EventType(self::CUSTOMER_CREATED);
        yield new EventType(self::CUSTOMER_UPDATED);
        yield new EventType(self::PRODUCT_VARIANT_STOCK_CHANGED);
        yield new EventType(self::WEBHOOK_PING, subscribable: false);
    }
}
