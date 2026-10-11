<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Capture\Detector;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\ChangeDetectorInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Capture\EntityGuard;
use Tmonier\SyliusWebhookOutboxPlugin\EventType\CoreEventTypeProvider;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\WebhookEvent;
use Tmonier\SyliusWebhookOutboxPlugin\Payload\CustomerPayloadNormalizer;

/**
 * customer.created (guests included: Sylius creates a customer for every checkout e-mail) and
 * customer.updated, only when a field of CustomerPayloadNormalizer::UPDATE_FIELDS changes or
 * when an existing customer gets a shop account ("has_account").
 */
final class CustomerEventDetector implements ChangeDetectorInterface
{
    public function __construct(
        private readonly CustomerPayloadNormalizer $normalizer,
        private readonly EntityGuard $guard,
    ) {
    }

    public function detect(EntityManagerInterface $entityManager, UnitOfWork $unitOfWork): iterable
    {
        $created = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $customer) {
            if ($customer instanceof CustomerInterface) {
                $created[spl_object_id($customer)] = true;
                yield from $this->guard->events($customer, fn (): iterable => [
                    $this->event(CoreEventTypeProvider::CUSTOMER_CREATED, $customer, $this->normalizer->normalize($customer)),
                ]);
            }
        }

        /** @var array<int, array{CustomerInterface, list<string>}> $updated */
        $updated = [];
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
            if ([] !== $changed) {
                $updated[spl_object_id($customer)] = [$customer, $changed];
            }
        }

        // Sign-up of an existing customer (a guest registering): only a shop user is inserted.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $user) {
            if (!$user instanceof ShopUserInterface) {
                continue;
            }
            $customer = $user->getCustomer();
            if (!$customer instanceof CustomerInterface || isset($created[spl_object_id($customer)])) {
                continue;
            }
            $changed = $updated[spl_object_id($customer)][1] ?? [];
            $changed[] = 'has_account';
            $updated[spl_object_id($customer)] = [$customer, $changed];
        }

        foreach ($updated as [$customer, $changed]) {
            yield from $this->guard->events($customer, fn (): iterable => [
                $this->event(
                    CoreEventTypeProvider::CUSTOMER_UPDATED,
                    $customer,
                    $this->normalizer->normalize($customer) + ['changed_fields' => $changed],
                ),
            ]);
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
