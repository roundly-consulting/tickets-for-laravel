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
     * The assignee for the new ticket, or null to leave it unassigned. The model must implement
     * {@see Assignee}. Asked right after the ticket row is stored, in the creating transaction:
     * it has its key, reference, number and initial status, its requester, project, category,
     * priority and channel — not yet its custom fields, tags, watchers or attachments.
     */
    public function assigneeFor(Ticket $ticket): ?Model;
}
