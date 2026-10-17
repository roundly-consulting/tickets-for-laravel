<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;
use RoundlyConsulting\Tickets\Exceptions\UnknownMorphAliasException;

/**
 * The alias allow-lists that turn untrusted `{type: alias, id}` input into a model —
 * `tickets.requesters`, `tickets.assignables`, `tickets.watchables` (null = requesters ∪
 * assignables), `tickets.projects`. A class name is never accepted as a type, and the guest
 * model is always a valid requester (`guest`).
 *
 * @internal
 */
final class MorphAliases
{
    public const string REQUESTERS = 'requesters';

    public const string ASSIGNABLES = 'assignables';

    public const string PROJECTS = 'projects';

    public const string WATCHABLES = 'watchables';

    /**
     * @return array<string, class-string<Model>>
     */
    public static function map(string $kind): array
    {
        $raw = match ($kind) {
            self::REQUESTERS => config('tickets.requesters'),
            self::ASSIGNABLES => config('tickets.assignables'),
            self::PROJECTS => config('tickets.projects'),
            self::WATCHABLES => config('tickets.watchables'),
            default => throw UnknownMorphAliasException::unknownKind($kind),
        };

        // No watchables list: whoever may request or be assigned may watch.
        if ($kind === self::WATCHABLES && $raw === null) {
            return [...self::map(self::ASSIGNABLES), ...self::map(self::REQUESTERS)];
        }

        $raw ??= [];

        if (! is_array($raw)) {
            throw InvalidTicketsConfigurationException::invalid('tickets.'.$kind, 'a map of alias => model class');
        }

        $map = [];

        foreach ($raw as $alias => $class) {
            if (! is_string($alias) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $alias) !== 1
                || ! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw InvalidTicketsConfigurationException::invalid('tickets.'.$kind, 'a map of snake_case alias => model class');
            }

            $map[$alias] = $class;
        }

        if ($kind === self::REQUESTERS) {
            $map['guest'] ??= TicketModels::guest();
        }

        return $map;
    }

    /**
     * The model an `{alias, key}` pair names, or a 422-mapped refusal for an alias (or a
     * class name) the allow-list does not hold, a class missing its contract, or a key
     * that matches no row.
     */
    public static function resolve(string $kind, string $alias, int|string $key): Model
    {
        $class = self::map($kind)[$alias] ?? throw UnknownMorphAliasException::alias($kind, $alias);

        if ($kind === self::ASSIGNABLES && ! is_subclass_of($class, Assignee::class)) {
            throw UnknownMorphAliasException::alias($kind, $alias);
        }

        if ($kind === self::PROJECTS && ! is_subclass_of($class, TicketProject::class)) {
            throw UnknownMorphAliasException::alias($kind, $alias);
        }

        return $class::query()->find($key) ?? throw UnknownMorphAliasException::missing($kind, $alias, $key);
    }

    /**
     * The alias a model is known by in one allow-list, or null when its class is not on it.
     */
    public static function aliasFor(string $kind, Model $model): ?string
    {
        foreach (self::map($kind) as $alias => $class) {
            if ($model::class === $class) {
                return $alias;
            }
        }

        return null;
    }
}
