<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The priority of a ticket changed (raw stored values). Dispatched after the transaction commits.
 */
final readonly class TicketPriorityChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public ?string $from,
        public string $to,
        public ?Model $actor,
    ) {}
}
