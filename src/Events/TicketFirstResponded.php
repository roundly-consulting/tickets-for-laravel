<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The first public agent reply of the ticket stamped `first_responded_at` — once per ticket.
 * Dispatched after the transaction commits.
 */
final readonly class TicketFirstResponded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public Comment $comment,
        public ?Model $actor,
    ) {}
}
