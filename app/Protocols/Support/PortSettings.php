<?php

declare(strict_types=1);

namespace App\Protocols\Support;

class PortSettings
{
    public static function isMultiple($ports): bool
    {
        return strpos((string) $ports, ',') !== false || strpos((string) $ports, '-') !== false;
    }

    /** Select one port for protocols that cannot advertise port hopping. */
    public static function select($ports): int
    {
        $parts = array_map('trim', explode(',', (string) $ports));
        $range = explode('-', $parts[array_rand($parts)], 2);

        return isset($range[1]) ? rand((int) $range[0], (int) $range[1]) : (int) $range[0];
    }

    /** sing-box 1.12+ requires ranges, including a repeated endpoint for individual ports. */
    public static function forSingbox($ports): array
    {
        if (!self::isMultiple($ports)) {
            return ['server_port' => (int) $ports];
        }

        return ['server_ports' => array_map(
            static function ($part) {
                $part = trim($part);

                return strpos($part, '-') !== false ? str_replace('-', ':', $part) : "{$part}:{$part}";
            },
            explode(',', (string) $ports)
        )];
    }
}
