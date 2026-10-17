<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RuntimeException;

/**
 * Base class of every exception the package throws, so a host can catch them all at once.
 * Messages come from the `tickets::messages` translation group.
 */
abstract class TicketsException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function translate(string $key, array $replace = []): string
    {
        $line = __('tickets::messages.'.$key, $replace);

        return is_string($line) ? $line : $key;
    }
}
