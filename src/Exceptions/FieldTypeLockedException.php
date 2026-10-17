<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A field that already holds values cannot change its type or encryption, nor drop an option a
 * value still uses — the stored values would no longer read back. Maps to 409.
 */
final class FieldTypeLockedException extends TicketsException
{
    public static function type(string $key): self
    {
        return new self(self::translate('field.type_locked', ['key' => $key]));
    }

    public static function encryption(string $key): self
    {
        return new self(self::translate('field.encryption_locked', ['key' => $key]));
    }

    /**
     * @param  list<string>  $options
     */
    public static function optionsInUse(string $key, array $options): self
    {
        return new self(self::translate('field.options_in_use', ['key' => $key, 'options' => implode(', ', $options)]));
    }
}
