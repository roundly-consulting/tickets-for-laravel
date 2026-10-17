<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The due date of a ticket was set, moved or cleared. Dispatched after the transaction commits.
 */
final readonly class TicketDueDateChanged implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $ticket,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
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
