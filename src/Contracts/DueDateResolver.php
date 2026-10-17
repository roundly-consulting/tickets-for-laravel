<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Picks the due date of a ticket created without one (`tickets.due.resolver`) — the seam a
 * future SLA layer plugs into.
 */
interface DueDateResolver
{
    /**
     * The due date for the new ticket, or null for none. Asked on the stored ticket, as
     * {@see AssignmentStrategy::assigneeFor()} is.
     */
    public function dueAt(Ticket $ticket): ?CarbonImmutable;
}
