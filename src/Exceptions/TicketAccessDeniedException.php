<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The author holds no role on the ticket that allows the write: only its requester, an agent
 * or a watcher may reply, and only an agent may add an internal note. Maps to 403.
 */
final class TicketAccessDeniedException extends TicketsException
{
    public static function notAParticipant(Ticket $ticket): self
    {
        return new self(self::translate('conversation.not_a_participant', ['reference' => $ticket->reference]));
    }

    public static function notAnAgent(Ticket $ticket): self
    {
        return new self(self::translate('conversation.not_an_agent', ['reference' => $ticket->reference]));
    }
}
