<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\EntityGuard;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\ProductVariantPayloadNormalizer;

/**
 * product_variant.stock_changed, coalesced per flush: one event listing every variant whose
 * on_hand or on_hold changed in the flush, and the new tracked variants (initial stock,
 * previous values null). Placing an order holds stock on each of its
 * variants; without coalescing, every order would produce one event per line.
 */
final readonly class StockChangedDetector implements ChangeDetectorInterface
{
    public function __construct(
        private ProductVariantPayloadNormalizer $normalizer,
        private EntityGuard $guard,
    ) {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        $variants = [];
        $ids = [];

        // New tracked variants: their initial stock (previous values null).
        foreach ($unitOfWork->getScheduledEntityInsertions() as $variant) {
            if ($variant instanceof ProductVariantInterface && $variant->isTracked()) {
                $this->collect($variant, null, null, $variants, $ids);
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($variant);
            $onHand = $this->change($changeSet['onHand'] ?? null);
            $onHold = $this->change($changeSet['onHold'] ?? null);
            if (null === $onHand && null === $onHold) {
                continue;
            }

            $this->collect($variant, $onHand[0] ?? $variant->getOnHand(), $onHold[0] ?? $variant->getOnHold(), $variants, $ids);
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
     * @param list<array<string, mixed>> $variants
     * @param list<int|string|LazyValue> $ids
     */
    private function collect(ProductVariantInterface $variant, ?int $previousOnHand, ?int $previousOnHold, array &$variants, array &$ids): void
    {
        // Same isolation as the other detectors: a failing line does not drop the others.
        $line = $this->guard->call($variant, fn (): array => $this->normalizer->normalizeStock($variant, $previousOnHand, $previousOnHold));
        if (null !== $line) {
            $variants[] = $line;
            $ids[] = LazyValue::idOf($variant);
        }
    }

    /**
     * @return array{0: int|null, 1: int|null}|null [old, new] when the value really changed
     */
    private function change(mixed $change): ?array
    {
        if (!is_array($change)) {
            return null;
        }
        $old = is_numeric($change[0] ?? null) ? (int) $change[0] : null;
        $new = is_numeric($change[1] ?? null) ? (int) $change[1] : null;

        return $old === $new ? null : [$old, $new];
    }
}
