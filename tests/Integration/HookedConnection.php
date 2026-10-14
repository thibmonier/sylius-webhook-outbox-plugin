<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusWebhookOutboxPlugin\Integration;

use Doctrine\DBAL\Connection;

/**
 * DBAL connection of a second worker that runs a callback right after its first read
 * mentioning the endpoint state: lets a test change the endpoint between that read and the
 * worker's next statement.
 */
final class HookedConnection extends Connection
{
    public static ?\Closure $afterEndpointRead = null;

    public function fetchFirstColumn(string $query, array $params = [], array $types = []): array
    {
        $result = parent::fetchFirstColumn($query, $params, $types);
        if (self::$afterEndpointRead instanceof \Closure && str_contains($query, 'enabled')) {
            $callback = self::$afterEndpointRead;
            self::$afterEndpointRead = null;
            $callback();
        }

        return $result;
    }
}
