<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Who is an agent: an authenticatable model the host's agent ability
 * (`tickets.authorization.abilities.agent`) allows. Anything that cannot log in — a guest, a
 * team, a contact — is never an agent, and an undefined ability denies (fail closed).
 *
 * @internal
 */
final class Agents
{
    public static function is(?Model $user): bool
    {
        if (! $user instanceof Authenticatable) {
            return false;
        }

        return App::make(Gate::class)->forUser($user)->allows(TicketsConfig::agentAbility());
    }
}
