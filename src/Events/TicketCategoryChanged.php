<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A ticket moved to another category (or out of every category). Dispatched after the transaction commits.
 */
final readonly class TicketCategoryChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public ?int $from,
        public ?int $to,
        public ?Model $actor,
    ) {}
}
