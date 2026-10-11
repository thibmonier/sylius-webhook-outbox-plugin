<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\ProductVariantPayloadNormalizer;

/**
 * product_variant.stock_changed, coalesced per flush: one event listing every variant whose
 * on_hand or on_hold changed in the flush. Placing an order holds stock on each of its
 * variants; without coalescing, every order would produce one event per line.
 */
final class StockChangedDetector implements ChangeDetectorInterface
{
    public function __construct(private readonly ProductVariantPayloadNormalizer $normalizer)
    {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        $variants = [];
        $ids = [];
        foreach ($unitOfWork->getScheduledEntityUpdates() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($variant);
            $onHand = self::change($changeSet['onHand'] ?? null);
            $onHold = self::change($changeSet['onHold'] ?? null);
            if (null === $onHand && null === $onHold) {
                continue;
            }

            $variants[] = $this->normalizer->normalizeStock(
                $variant,
                $onHand[0] ?? $variant->getOnHand(),
                $onHold[0] ?? $variant->getOnHold(),
            );
            $ids[] = LazyValue::idOf($variant);
        }

        if ([] === $variants) {
            return;
        }

        yield new WebhookEvent(
            CoreEventTypeProvider::PRODUCT_VARIANT_STOCK_CHANGED,
            ['variants' => $variants],
            null,
            'product_variant',
            1 === count($ids) ? $ids[0] : null,
        );
    }

    /**
     * @return array{0: int|null, 1: int|null}|null [old, new] when the value really changed
     */
    private static function change(mixed $change): ?array
    {
        if (!is_array($change)) {
            return null;
        }
        $old = is_numeric($change[0] ?? null) ? (int) $change[0] : null;
        $new = is_numeric($change[1] ?? null) ? (int) $change[1] : null;

        return $old === $new ? null : [$old, $new];
    }
}
