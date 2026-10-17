<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use BackedEnum;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The status of a ticket changed — every transition, expiry, scheduled run, rollback step
 * and adoption; never the initial state. Dispatched after the transaction commits.
 */
final readonly class TicketStatusChanged implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $ticket,
        public ?string $transition,
        public BackedEnum|string|null $from,
        public BackedEnum|string $to,
        public ?Model $actor,
        public bool $system,
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
