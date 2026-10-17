<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

/**
 * A line of the `tickets::messages` translation group.
 *
 * @internal
 */
final class Messages
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $line = __('tickets::messages.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
