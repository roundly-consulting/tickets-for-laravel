<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A morph type outside its alias allow-list (an unknown alias, or a class name), or a key
 * that matches no row. Maps to 422 (`unknown_<kind>_type`).
 */
final class UnknownMorphAliasException extends TicketsException
{
    private string $kind = '';

    public static function alias(string $kind, string $alias): self
    {
        return self::of($kind, self::translate('alias.unknown', ['kind' => $kind, 'alias' => $alias]));
    }

    public static function missing(string $kind, string $alias, int|string $key): self
    {
        return self::of($kind, self::translate('alias.missing', ['kind' => $kind, 'alias' => $alias, 'key' => (string) $key]));
    }

    public static function unknownKind(string $kind): self
    {
        return self::of($kind, self::translate('alias.unknown_kind', ['kind' => $kind]));
    }

    /**
     * The API error code: `unknown_requester_type`, `unknown_assignee_type`,
     * `unknown_watcher_type` or `unknown_project_type`.
     */
    public function errorCode(): string
    {
        return match ($this->kind) {
            'requesters' => 'unknown_requester_type',
            'assignables' => 'unknown_assignee_type',
            'watchables' => 'unknown_watcher_type',
            'projects' => 'unknown_project_type',
            default => 'unknown_type',
        };
    }

    private static function of(string $kind, string $message): self
    {
        $exception = new self($message);
        $exception->kind = $kind;

        return $exception;
    }
}
