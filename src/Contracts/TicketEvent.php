<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Every ticket event — one type to listen to for an audit log, webhooks or a dashboard feed:
 * `Event::listen(TicketEvent::class, fn (TicketEvent $event) => …)`. Laravel's dispatcher
 * delivers each event to the listeners of the interfaces it implements.
 *
 * `GuestConverted` is not one: it concerns a guest and all of their tickets, not one ticket.
 */
interface TicketEvent
{
    /**
     * The ticket the event is about — for `TicketMerged`, the source (the ticket merged away).
     */
    public function ticket(): Ticket;

    /**
     * Who acted — null for the system, and for the events nobody acts in: `TicketOverdue`,
     * `TicketDueSoon` (the sweep) and `TicketMentioned` (the mention's author is the comment's).
     */
    public function actor(): ?Model;
}
