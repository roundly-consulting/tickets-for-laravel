<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * @internal
 */
final class Assignees
{
    /**
     * The model, once it is known to implement {@see Assignee} and to be eligible for the
     * ticket.
     */
    public static function assertAssignable(Model $assignee, Ticket $ticket): Model&Assignee
    {
        if (! $assignee instanceof Assignee) {
            throw InvalidAssigneeException::notAnAssignee($assignee);
        }

        if (! $assignee->isEligibleTicketAssignee($ticket)) {
            throw InvalidAssigneeException::ineligible($assignee);
        }

        return $assignee;
    }

    public static function isAssignable(Model $assignee, Ticket $ticket): bool
    {
        return $assignee instanceof Assignee && $assignee->isEligibleTicketAssignee($ticket);
    }
}
