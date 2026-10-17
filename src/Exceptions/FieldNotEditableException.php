<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A custom field value cannot be written: the key is not a field of this ticket (unknown, of
 * another project or category, deleted or inactive — tickets checks this itself, never through
 * attributes' global strict mode), or the writer is not an agent and the field is not
 * `requester_editable`. Maps to 422 (`unknown_field` / `field_not_editable`).
 */
final class FieldNotEditableException extends TicketsException
{
    public static function unknown(string $key): self
    {
        return new self(self::translate('field.unknown', ['key' => $key]));
    }

    public static function notEditable(string $key): self
    {
        return new self(self::translate('field.not_editable', ['key' => $key]));
    }
}
