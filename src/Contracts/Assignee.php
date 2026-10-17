<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A model a ticket can be assigned to: a user, a team, a queue. The
 * {@see IsTicketAssignee} trait supplies defaults.
 */
interface Assignee
{
    /** The display name shown on the ticket. */
    public function ticketAssigneeName(): string;

    /**
     * Who is notified on the assignee's behalf (a team returns its members).
     *
     * @return iterable<Model>
     */
    public function ticketNotifiables(): iterable;

    /** Whether the ticket may be assigned to this model. */
    public function isEligibleTicketAssignee(Ticket $ticket): bool;
}
