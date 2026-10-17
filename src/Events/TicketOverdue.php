<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * An unresolved ticket passed its due date — fired by `tickets:sweep` exactly once per due
 * date: concurrent sweeps flag it once, and only moving the due date re-arms it. Dispatched
 * after the transaction commits.
 */
final readonly class TicketOverdue implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $ticket,
        public CarbonImmutable $dueAt,
    ) {}

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function actor(): ?Model
    {
        return null;
    }
}
