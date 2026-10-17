<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A custom field value cannot be written: the key is not a field of this ticket (unknown, of
 * another project or category, deleted or inactive — tickets checks this itself, never through
 * attributes' global strict mode), or the writer is not an agent and the field is not
 * `requester_editable`. Maps to 422; {@see self::errorCode()} tells the two apart
 * (`unknown_field` / `field_not_editable`).
 */
final class FieldNotEditableException extends TicketsException
{
    public const string UNKNOWN = 'unknown_field';

    public const string NOT_EDITABLE = 'field_not_editable';

    private string $reason = self::NOT_EDITABLE;

    public static function unknown(string $key): self
    {
        $exception = new self(self::translate('field.unknown', ['key' => $key]));
        $exception->reason = self::UNKNOWN;

        return $exception;
    }

    public static function notEditable(string $key): self
    {
        return new self(self::translate('field.not_editable', ['key' => $key]));
    }

    /**
     * Why the write was refused: {@see self::UNKNOWN} (not a field of this ticket) or
     * {@see self::NOT_EDITABLE} (an agent-only field).
     */
    public function errorCode(): string
    {
        return $this->reason;
    }
}
