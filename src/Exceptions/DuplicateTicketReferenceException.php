<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * The allocated reference is already taken — two projects share a reference prefix, which
 * they must not. Maps to 409.
 */
final class DuplicateTicketReferenceException extends TicketsException
{
    public static function for(string $reference): self
    {
        return new self(self::translate('ticket.duplicate_reference', ['reference' => $reference]));
    }
}
