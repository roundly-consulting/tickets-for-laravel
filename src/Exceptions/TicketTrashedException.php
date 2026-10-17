<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A soft-deleted ticket is read-only until it is restored — every mutation is refused, as
 * lifecycle refuses its transitions. Maps to 409.
 */
final class TicketTrashedException extends TicketsException
{
    public static function for(Ticket $ticket): self
    {
        return new self(self::translate('ticket.trashed', ['reference' => $ticket->reference]));
    }
}
