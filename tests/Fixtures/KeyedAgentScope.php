<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Tests\Fixtures\Models\Project;

/**
 * A host scope kept in parity with its list form by construction: `allows()` asks the database
 * the same query `constrain()` narrows a list with, for the one ticket — so it only works on a
 * stored ticket. Agents work on SUP tickets.
 */
final readonly class KeyedAgentScope implements AgentScope
{
    public function allows(Model $agent, Ticket $ticket): bool
    {
        return Ticket::query()->whereKey($ticket->getKey())->where(fn (Builder $query) => $this->constrain($query, $agent))->exists();
    }

    /**
     * @param  Builder<covariant Ticket>  $query
     */
    public function constrain(Builder $query, Model $agent): void
    {
        $sup = Project::query()->where('key', 'SUP')->first();

        $query->where('project_type', $sup?->getMorphClass())->where('project_id', $sup?->getKey());
    }
}
