<?php

declare(strict_types=1);

namespace Tmonier\SyliusWebhookOutboxPlugin\Http;

/**
 * CIDR subtraction: the blocked private ranges minus the "allowed_private_networks", so that
 * NoPrivateNetworkHttpClient keeps enforcing everything else.
 */
final class SubnetCalculator
{
    /**
     * @param list<string> $blocked
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function subtract(array $blocked, array $allowed): array
    {
        $allowedNetworks = array_map(self::parse(...), $allowed);
        $result = [];

        foreach ($blocked as $cidr) {
            $pieces = [self::parse($cidr)];
            foreach ($allowedNetworks as $hole) {
                $next = [];
                foreach ($pieces as $piece) {
                    array_push($next, ...self::subtractOne($piece, $hole));
                }
                $pieces = $next;
            }
            foreach ($pieces as $piece) {
                $result[] = self::format($piece);
            }
        }

        return $result;
    }

    /**
     * @param array{bits: string, v6: bool} $network
     * @param array{bits: string, v6: bool} $hole
     *
     * @return list<array{bits: string, v6: bool}>
     */
    private static function subtractOne(array $network, array $hole): array
    {
        if ($network['v6'] !== $hole['v6']) {
            return [$network];
        }

        $networkLength = strlen($network['bits']);
        $holeLength = strlen($hole['bits']);

        // The network is inside the hole: nothing left.
        if ($holeLength <= $networkLength && str_starts_with($network['bits'], $hole['bits'])) {
            return [];
        }

        // Disjoint.
        if (!str_starts_with($hole['bits'], $network['bits'])) {
            return [$network];
        }

        // The hole is inside the network: keep the siblings along the path to the hole.
        $pieces = [];
        for ($i = $networkLength; $i < $holeLength; ++$i) {
            $pieces[] = ['bits' => substr($hole['bits'], 0, $i) . ('0' === $hole['bits'][$i] ? '1' : '0'), 'v6' => $network['v6']];
        }

        return $pieces;
    }

    /** @return array{bits: string, v6: bool} network prefix as a string of "0" and "1" */
    private static function parse(string $cidr): array
    {
        [$address, $length] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $packed = @inet_pton($address);
        if (false === $packed) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid IP address or CIDR range.', $cidr));
        }

        $v6 = 16 === strlen($packed);
        $bits = '';
        foreach (str_split($packed) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $length = null === $length ? strlen($bits) : (int) $length;

        return ['bits' => substr($bits, 0, $length), 'v6' => $v6];
    }

    /** @param array{bits: string, v6: bool} $network */
    private static function format(array $network): string
    {
        $bits = str_pad($network['bits'], $network['v6'] ? 128 : 32, '0');
        $packed = '';
        foreach (str_split($bits, 8) as $byte) {
            $packed .= chr((int) bindec($byte));
        }

        return sprintf('%s/%d', (string) inet_ntop($packed), strlen($network['bits']));
    }
}
