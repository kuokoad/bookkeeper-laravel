<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A database row as the old services returned it: camelCase keys, flags as
 * booleans. Values are otherwise as MySQL gave them (BIGINT arrives as int).
 */
final class Row
{
    private const FLAGS = ['tax_inclusive', 'is_demo', 'is_active', 'track_inventory', 'is_recoverable'];

    public static function camel(object $row): array
    {
        $out = [];
        foreach ((array) $row as $key => $value) {
            $out[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = in_array($key, self::FLAGS, true) && $value !== null ? (bool) $value : $value;
        }

        return $out;
    }
}
