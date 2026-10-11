<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Payload;

use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

/** Stock line of product_variant.stock_changed, version 1. */
final class ProductVariantPayloadNormalizer
{
    public const VERSION = 1;

    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    /** @return array<string, mixed> */
    public function normalizeStock(ProductVariantInterface $variant, ?int $previousOnHand, ?int $previousOnHold): array
    {
        $id = LazyValue::idOf($variant);
        $productId = null === $variant->getProduct() ? null : LazyValue::idOf($variant->getProduct());
        $onHand = (int) $variant->getOnHand();
        $onHold = (int) $variant->getOnHold();

        return [
            'id' => $id,
            'code' => $variant->getCode(),
            'product_code' => $variant->getProduct()?->getCode(),
            'admin_url' => $this->adminUrlGenerator->generate('sylius_admin_product_variant_update', ['productId' => $productId, 'id' => $id]),
            'tracked' => $variant->isTracked(),
            'on_hand' => $onHand,
            'on_hold' => $onHold,
            'available' => $onHand - $onHold,
            'previous' => [
                'on_hand' => $previousOnHand,
                'on_hold' => $previousOnHold,
            ],
        ];
    }
}
