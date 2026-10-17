<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Guest access refused: a link that is invalid, expired, tampered with, revoked or meant for
 * someone else (one uniform answer, never saying which — 403 `guest_link_invalid`), or a link
 * asked for someone who is neither the ticket's guest requester nor one of its guest watchers.
 * Maps to 403.
 */
final class GuestAccessDeniedException extends TicketsException
{
    public static function invalidLink(): self
    {
        return new self(self::translate('guest.link_invalid'));
    }

    public static function notARecipient(Ticket $ticket): self
    {
        return new self(self::translate('guest.not_a_recipient', ['reference' => $ticket->reference]));
    }
}
