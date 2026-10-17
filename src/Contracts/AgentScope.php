<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Narrows the tickets an agent may work on — by project membership, team, region. Bound from
 * `tickets.authorization.agent_scope`; the default lets every agent work on every ticket.
 *
 * Both methods must agree: `allows()` decides one ticket (the policy, a resource), `constrain()`
 * narrows a list (`Ticket::visibleTo()`, the index), and a test pins that they return the same
 * tickets. An agent outside the scope still sees a ticket they requested or watch. `allows()`
 * always gets a stored ticket — at creation too (assignee eligibility is judged after the insert).
 */
interface AgentScope
{
    /**
     * Whether the agent may work on the ticket.
     */
    public function allows(Model $agent, Ticket $ticket): bool;

    /**
     * Narrow a ticket query to the tickets the agent may work on. Add `where` clauses only —
     * they are grouped and OR-ed with the requester and watcher branches. Add nothing for "all".
     *
     * @param  Builder<covariant Ticket>  $query
     */
    public function constrain(Builder $query, Model $agent): void;
}
