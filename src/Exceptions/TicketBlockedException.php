<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket cannot start: with `tickets.links.blockers_prevent_start` on, it would enter an
 * Active-category state while unresolved tickets still `block` it. Resolve (or unlink) the
 * blockers first. Maps to 409 (`ticket_blocked`).
 */
final class TicketBlockedException extends TicketsException
{
    /** @var list<string> */
    private array $blockers = [];

    /**
     * @param  list<string>  $blockers  the references of the open blockers
     */
    public static function byOpenBlockers(Ticket $ticket, array $blockers): self
    {
        $exception = new self(self::translate('link.blocked_start', [
            'reference' => $ticket->reference,
            'blockers' => implode(', ', $blockers),
        ]));
        $exception->blockers = $blockers;

        return $exception;
    }

    /**
     * The references of the unresolved tickets that block this one, in key order.
     *
     * @return list<string>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
