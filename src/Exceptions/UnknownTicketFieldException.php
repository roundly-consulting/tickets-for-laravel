<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A custom field cannot be filtered on: no live, active field has the key, or it is encrypted
 * (an encrypted value never matches — and filtering would probe it), a multiselect (stored as a
 * json list), or — for a range — not a number, decimal, date or datetime. Maps to 422.
 */
final class UnknownTicketFieldException extends TicketsException
{
    public static function unknown(string $key): self
    {
        return new self(self::translate('field.unknown', ['key' => $key]));
    }

    public static function encrypted(string $key): self
    {
        return new self(self::translate('field.not_filterable_encrypted', ['key' => $key]));
    }

    public static function notFilterable(string $key): self
    {
        return new self(self::translate('field.not_filterable', ['key' => $key]));
    }
}
