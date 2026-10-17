<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Authorization;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\AgentScope;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The default {@see AgentScope}: every agent may work on every ticket.
 */
final readonly class UnrestrictedAgentScope implements AgentScope
{
    public function allows(Model $agent, Ticket $ticket): bool
    {
        return true;
    }

    /**
     * @param  Builder<covariant Ticket>  $query
     */
    public function constrain(Builder $query, Model $agent): void {}
}
