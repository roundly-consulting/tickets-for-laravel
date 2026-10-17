<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * No live ticket has the reference (or key) — unknown, or soft-deleted. Maps to 404.
 */
final class TicketNotFoundException extends TicketsException
{
    public static function forReference(string $reference): self
    {
        return new self(self::translate('ticket.not_found', ['reference' => $reference]));
    }

    public static function forKey(int|string|null $key): self
    {
        return new self(self::translate('ticket.not_found', ['reference' => (string) $key]));
    }
}
