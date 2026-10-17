<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\MorphAliases;

/**
 * How a person (a requester, an assignee, a message author, a watcher) shows in a response: the
 * alias of its type — never a class name — and, as far as the viewer may know, its key and name.
 * A guest's fields are personal data: agents see them, a guest sees their own name, nobody else
 * sees either.
 *
 * @internal
 */
final class Party
{
    /** The allow-lists an alias is looked up in, in order. */
    private const array KINDS = [MorphAliases::REQUESTERS, MorphAliases::ASSIGNABLES, MorphAliases::WATCHABLES];

    /**
     * @return array<string, mixed>|null
     */
    public static function person(?Model $model, Viewer $viewer, bool $agent): ?array
    {
        if ($model === null) {
            return null;
        }

        if ($agent) {
            return [
                'type' => self::alias($model),
                'id' => $model->getKey(),
                'name' => self::name($model),
                ...($model instanceof TicketGuest ? ['guest' => TicketGuestResource::fields($model)] : []),
            ];
        }

        $private = $model instanceof TicketGuest && ! $viewer->is($model);

        return ['type' => self::alias($model), 'name' => $private ? null : self::name($model)];
    }

    /**
     * The requester: everyone but an agent learns only its type, unless it is themselves.
     *
     * @return array<string, mixed>|null
     */
    public static function requester(?Model $model, Viewer $viewer, bool $agent): ?array
    {
        if ($model === null || $agent || $viewer->is($model)) {
            return self::person($model, $viewer, $agent);
        }

        return ['type' => self::alias($model)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function project(?Model $model): ?array
    {
        return $model === null ? null : [
            'type' => MorphAliases::aliasFor(MorphAliases::PROJECTS, $model),
            'id' => $model->getKey(),
        ];
    }

    public static function alias(Model $model): ?string
    {
        foreach (self::KINDS as $kind) {
            $alias = MorphAliases::aliasFor($kind, $model);

            if ($alias !== null) {
                return $alias;
            }
        }

        return null;
    }

    public static function name(Model $model): ?string
    {
        if ($model instanceof TicketGuest) {
            return $model->displayName();
        }

        if ($model instanceof Assignee) {
            return $model->ticketAssigneeName();
        }

        $name = $model->getAttribute('name');

        return is_string($name) ? $name : null;
    }
}
