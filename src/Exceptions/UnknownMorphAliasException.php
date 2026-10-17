<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A morph type outside its alias allow-list (an unknown alias, or a class name), or a key
 * that matches no row. Maps to 422 (`unknown_<kind>_type`).
 */
final class UnknownMorphAliasException extends TicketsException
{
    public static function alias(string $kind, string $alias): self
    {
        return new self(self::translate('alias.unknown', ['kind' => $kind, 'alias' => $alias]));
    }

    public static function missing(string $kind, string $alias, int|string $key): self
    {
        return new self(self::translate('alias.missing', ['kind' => $kind, 'alias' => $alias, 'key' => (string) $key]));
    }

    public static function unknownKind(string $kind): self
    {
        return new self(self::translate('alias.unknown_kind', ['kind' => $kind]));
    }
}
