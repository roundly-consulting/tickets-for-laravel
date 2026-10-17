<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The subject or description of a ticket changed. Dispatched after the transaction commits.
 */
final readonly class TicketUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $changed  the changed attributes
     */
    public function __construct(
        public Ticket $ticket,
        public array $changed,
        public ?Model $actor,
    ) {}
}
