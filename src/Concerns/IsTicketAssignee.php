<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Defaults for {@see Assignee}: the name is the `name` attribute (else the key), the model
 * itself is notified, and a model that can log in is eligible when it is an agent for the
 * ticket (the `tickets.authorization.abilities.agent` Gate ability, narrowed by the agent
 * scope) — anything else (a team, a queue) is always eligible. Override any of them; a team returns its members from
 * `ticketNotifiables()`.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Assignee
 */
trait IsTicketAssignee
{
    public function ticketAssigneeName(): string
    {
        $name = $this->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : (string) $this->getKey();
    }

    /**
     * @return iterable<Model>
     */
    public function ticketNotifiables(): iterable
    {
        return [$this];
    }

    public function isEligibleTicketAssignee(Ticket $ticket): bool
    {
        return ! $this instanceof Authenticatable || TicketVisibility::isAgentFor($this, $ticket);
    }
}
