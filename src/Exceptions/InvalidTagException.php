<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A tag cannot be created, renamed or attached: a bad name or colour, a name its scope
 * already holds, an unknown name while free-form tags are off, or another project's tag.
 * Maps to 422.
 */
final class InvalidTagException extends TicketsException
{
    public static function invalidName(): self
    {
        return new self(self::translate('tag.invalid_name'));
    }

    public static function invalidColor(string $color): self
    {
        return new self(self::translate('tag.invalid_color', ['color' => $color]));
    }

    public static function duplicate(string $name): self
    {
        return new self(self::translate('tag.duplicate', ['name' => $name]));
    }

    public static function unknown(string $name): self
    {
        return new self(self::translate('tag.unknown', ['name' => $name]));
    }

    public static function projectMismatch(string $name): self
    {
        return new self(self::translate('tag.project_mismatch', ['name' => $name]));
    }
}
