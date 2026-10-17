<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket lost its assignee — null when that model no longer exists. Dispatched after the
 * transaction commits.
 */
final readonly class TicketUnassigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public ?Model $assignee,
        public ?Model $actor,
    ) {}
}
