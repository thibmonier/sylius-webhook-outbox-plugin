<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Maintenance;

/**
 * Replaces personal values of a payload by null, whatever their depth. Ids, codes, amounts and
 * states are kept so that the event stays meaningful for statistics.
 */
final readonly class PayloadRedactor
{
    public const PERSONAL_KEYS = [
        'email',
        'first_name',
        'last_name',
        'full_name',
        'phone_number',
        'birthday',
        'gender',
        'company',
        'street',
        'city',
        'postcode',
        'province_code',
        'province_name',
        'notes',
        'tracking',
    ];

    /** @param list<string> $personalKeys */
    public function __construct(private array $personalKeys = self::PERSONAL_KEYS)
    {
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, $this->personalKeys, true)) {
                $payload[$key] = null;
            } elseif (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
