<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Payload;

use Sylius\Component\Core\Model\CustomerInterface;
use Tmonier\SyliusWebhookOutboxPlugin\Outbox\LazyValue;

/**
 * Customer DTO, version 1 (see README, "Payloads"). Never exposes the shop user: no password,
 * no token, no roles; only whether the customer has an account.
 */
final class CustomerPayloadNormalizer
{
    public const VERSION = 1;

    /**
     * Entity fields that trigger customer.updated, mapped to their payload names.
     */
    public const UPDATE_FIELDS = [
        'email' => 'email',
        'firstName' => 'first_name',
        'lastName' => 'last_name',
        'phoneNumber' => 'phone_number',
        'gender' => 'gender',
        'birthday' => 'birthday',
        'group' => 'group',
        'subscribedToNewsletter' => 'subscribed_to_newsletter',
    ];

    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    /** @return array<string, mixed> */
    public function normalize(CustomerInterface $customer): array
    {
        $id = LazyValue::idOf($customer);

        return [
            'id' => $id,
            'admin_url' => $this->adminUrlGenerator->generate('sylius_admin_customer_show', ['id' => $id]),
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'phone_number' => $customer->getPhoneNumber(),
            'gender' => $customer->getGender(),
            'birthday' => $customer->getBirthday()?->format('Y-m-d'),
            'group' => $customer->getGroup()?->getCode(),
            'subscribed_to_newsletter' => $customer->isSubscribedToNewsletter(),
            'has_account' => null !== $customer->getUser(),
            'created_at' => Dates::format($customer->getCreatedAt()),
        ];
    }

    /**
     * Small customer block embedded in order payloads.
     *
     * @return array<string, mixed>|null
     */
    public function summarize(?CustomerInterface $customer): ?array
    {
        if (null === $customer) {
            return null;
        }

        return [
            'id' => LazyValue::idOf($customer),
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'phone_number' => $customer->getPhoneNumber(),
            'has_account' => null !== $customer->getUser(),
        ];
    }
}
