<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Delivery;

use Tmonier\SyliusWebhookOutboxPlugin\Entity\DeliveryAttempt;

/**
 * Database-driven retries. schedule[n] is the delay before attempt n + 1: the default
 * [0, 5, 300, ...] means "immediately, then 5 s after the first failure, 5 min after the
 * second..."; its length is the number of attempts before the delivery goes dead.
 */
final class RetryPolicy
{
    /** Retry-After is honoured on these statuses. */
    public const RETRY_AFTER_STATUSES = [429, 502, 503, 504];

    /** Upper bound of a Retry-After delay, in seconds. */
    public const MAX_RETRY_AFTER = 86400;

    /** @var \Closure(int): int */
    private readonly \Closure $random;

    /**
     * @param list<int> $schedule
     * @param float $jitter random extra delay, as a fraction of the delay
     * @param (\Closure(int): int)|null $random random integer between 0 and the given maximum
     */
    public function __construct(
        private readonly array $schedule,
        private readonly float $jitter = 0.1,
        ?\Closure $random = null,
    ) {
        if ([] === $schedule) {
            throw new \InvalidArgumentException('The retry schedule needs at least one delay.');
        }
        $this->random = $random ?? static fn (int $max): int => random_int(0, $max);
    }

    public function maxAttempts(): int
    {
        return count($this->schedule);
    }

    public function firstAttemptDelay(): int
    {
        return $this->schedule[0];
    }

    /**
     * Delay before the next attempt, in seconds, or null when no attempt is left.
     */
    public function nextDelay(int $attemptsDone, DeliveryAttempt $lastAttempt): ?int
    {
        if ($attemptsDone >= $this->maxAttempts()) {
            return null;
        }

        $delay = $this->schedule[$attemptsDone];
        $jitterMax = (int) floor($delay * $this->jitter);
        if ($jitterMax > 0) {
            $delay += ($this->random)($jitterMax);
        }

        if (null !== $lastAttempt->retryAfterSeconds && in_array($lastAttempt->statusCode, self::RETRY_AFTER_STATUSES, true)) {
            $delay = max($delay, min($lastAttempt->retryAfterSeconds, self::MAX_RETRY_AFTER));
        }

        return $delay;
    }

    /**
     * Seconds from a Retry-After header: delta-seconds or HTTP-date.
     */
    public static function parseRetryAfter(?string $header, \DateTimeImmutable $now): ?int
    {
        if (null === $header || '' === trim($header)) {
            return null;
        }
        $header = trim($header);
        if (ctype_digit($header)) {
            return (int) $header;
        }

        $date = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $header, new \DateTimeZone('GMT'));
        if (false === $date) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now->getTimestamp());
    }
}
