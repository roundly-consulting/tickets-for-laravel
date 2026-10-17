<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A ticket was handed to another requester. Dispatched after the transaction commits.
 */
final readonly class TicketRequesterChanged implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $ticket,
        public Model $requester,
        public ?Model $actor,
    ) {}

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }
}
