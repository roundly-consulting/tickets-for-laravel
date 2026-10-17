<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A soft-deleted ticket was restored. Dispatched after the transaction commits.
 */
final readonly class TicketRestored implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public ?Model $actor,
    ) {}
}
