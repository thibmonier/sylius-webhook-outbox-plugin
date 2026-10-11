<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\CustomerPayloadNormalizer;

/**
 * customer.created (guests included: Sylius creates a customer for every checkout e-mail) and
 * customer.updated, only when a field of CustomerPayloadNormalizer::UPDATE_FIELDS changes.
 */
final class CustomerEventDetector implements ChangeDetectorInterface
{
    public function __construct(private readonly CustomerPayloadNormalizer $normalizer)
    {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        foreach ($unitOfWork->getScheduledEntityInsertions() as $customer) {
            if ($customer instanceof CustomerInterface) {
                yield $this->event(CoreEventTypeProvider::CUSTOMER_CREATED, $customer, $this->normalizer->normalize($customer));
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $customer) {
            if (!$customer instanceof CustomerInterface) {
                continue;
            }

            $changed = [];
            foreach ($unitOfWork->getEntityChangeSet($customer) as $field => $change) {
                if (isset(CustomerPayloadNormalizer::UPDATE_FIELDS[$field]) && is_array($change) && !self::same($change[0] ?? null, $change[1] ?? null)) {
                    $changed[] = CustomerPayloadNormalizer::UPDATE_FIELDS[$field];
                }
            }
            if ([] === $changed) {
                continue;
            }

            yield $this->event(
                CoreEventTypeProvider::CUSTOMER_UPDATED,
                $customer,
                $this->normalizer->normalize($customer) + ['changed_fields' => $changed],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function event(string $type, CustomerInterface $customer, array $data): WebhookEvent
    {
        $id = LazyValue::idOf($customer);

        return new WebhookEvent($type, $data, null, 'customer', $id, $id);
    }

    private static function same(mixed $old, mixed $new): bool
    {
        if ($old instanceof \DateTimeInterface && $new instanceof \DateTimeInterface) {
            return $old->format('Y-m-d') === $new->format('Y-m-d');
        }

        return $old === $new;
    }
}
