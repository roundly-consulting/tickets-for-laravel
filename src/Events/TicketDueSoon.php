<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * An unresolved ticket came within `tickets.due.soon_window` of its due date — fired by
 * `tickets:sweep` exactly once per due date, like {@see TicketOverdue}. Dispatched after the
 * transaction commits.
 */
final readonly class TicketDueSoon implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public CarbonImmutable $dueAt,
    ) {}
}
