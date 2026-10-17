<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Who a call is attributed to: the explicit actor, else (with `tickets.actor.from_auth`)
 * the authenticated user of `tickets.actor.guard`, else nobody (a system call).
 *
 * @internal
 */
final class ActorResolver
{
    public static function resolve(?Model $actor, bool $system = false): ?Model
    {
        if ($actor !== null || $system || ! TicketsConfig::actorFromAuth()) {
            return $actor;
        }

        $user = App::make(AuthFactory::class)->guard(TicketsConfig::actorGuard())->user();

        return $user instanceof Model ? $user : null;
    }
}
