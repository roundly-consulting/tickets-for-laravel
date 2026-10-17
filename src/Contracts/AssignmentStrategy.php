<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Picks the assignee of a ticket created without one (`tickets.assignment.strategy`).
 */
interface AssignmentStrategy
{
    /**
     * The assignee for the new, unsaved ticket, or null to leave it unassigned. The model
     * must implement {@see Assignee}.
     */
    public function assigneeFor(Ticket $ticket): ?Model;
}
