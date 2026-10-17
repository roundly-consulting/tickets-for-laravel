<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

/**
 * A line of the `tickets::messages` translation group, or an input's name from
 * `tickets::validation.attributes`.
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

    /**
     * The name validation messages give an input (`tickets::validation.attributes.<input>`),
     * else the input itself.
     */
    public static function attribute(string $input): string
    {
        $name = __('tickets::validation.attributes.'.$input);

        return is_string($name) && $name !== 'tickets::validation.attributes.'.$input ? $name : $input;
    }

    /**
     * A counted line (`trans_choice`), `:count` filled in.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function choice(string $key, int $count, array $replace = []): string
    {
        return trans_choice('tickets::messages.'.$key, $count, ['count' => $count, ...$replace]);
    }
}
