<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A `tickets.*` setting holds a value the package cannot use. Thrown when the setting is
 * read, naming the key, instead of falling back to a default.
 */
final class InvalidTicketsConfigurationException extends TicketsException
{
    public static function invalid(string $key, string $expected): self
    {
        return new self(self::translate('config.invalid', ['key' => $key, 'expected' => $expected]));
    }
}
