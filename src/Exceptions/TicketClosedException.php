<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket is in a Closed state: its conversation is locked. Open a follow-up ticket
 * instead. Maps to 409 (`ticket_closed`).
 */
final class TicketClosedException extends TicketsException
{
    public static function for(Ticket $ticket): self
    {
        return new self(self::translate('ticket.closed', ['reference' => $ticket->reference]));
    }
}
