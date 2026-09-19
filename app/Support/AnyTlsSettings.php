<?php

namespace App\Support;

use InvalidArgumentException;

class AnyTlsSettings
{
    /**
     * Parse and normalize the JSON editor value used by the admin panel.
     */
    public static function fromAdmin($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException('The AnyTLS padding scheme must be valid JSON.');
            }
        }

        if (!is_array($value) || !self::isList($value)) {
            throw new InvalidArgumentException('The AnyTLS padding scheme must be a JSON array.');
        }

        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new InvalidArgumentException('Every AnyTLS padding rule must be a string.');
            }
        }

        return array_values($value);
    }

    /**
     * Keep legacy malformed values from reaching v2bx's []string decoder.
     */
    public static function forNode($value): ?array
    {
        try {
            return self::fromAdmin($value);
        } catch (InvalidArgumentException $e) {
            return [];
        }
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
