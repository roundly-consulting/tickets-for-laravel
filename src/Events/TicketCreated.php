<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A ticket was created. Dispatched after the transaction commits.
 */
final readonly class TicketCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public ?Model $actor,
    ) {}
}
