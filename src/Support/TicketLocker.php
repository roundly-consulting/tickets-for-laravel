<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Lock order step one for every ticket mutation: the ticket row, `FOR UPDATE` — the same
 * first lock lifecycle's `SubjectLocker` takes, so a transition inside a tickets action
 * never inverts the order. The bound instance is refreshed from the locked row, so the
 * action decides on the stored truth and a chained handle call sees the previous call's
 * effect.
 *
 * @internal
 */
final class TicketLocker
{
    public static function lock(Ticket $ticket, bool $allowTrashed = false): Ticket
    {
        $stored = $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->lockForUpdate()->first();

        if ($stored === null) {
            throw TicketNotFoundException::forKey($ticket->getKey());
        }

        if (! $allowTrashed && $stored->trashed()) {
            throw TicketTrashedException::for($stored);
        }

        $ticket->setRawAttributes($stored->getAttributes(), true);
        $ticket->exists = true;

        return $ticket;
    }

    /**
     * Lock several tickets, always in primary-key order — two actions locking the same pair
     * (a merge, a link) then queue on the first row instead of deadlocking on crossed locks.
     */
    public static function lockInOrder(Ticket ...$tickets): void
    {
        usort($tickets, static fn (Ticket $a, Ticket $b): int => self::compareKeys($a->getKey(), $b->getKey()));

        foreach ($tickets as $ticket) {
            self::lock($ticket);
        }
    }

    /**
     * Primary keys in their natural order: numbers numerically, uuid/ulid strings as text.
     */
    public static function compareKeys(mixed $a, mixed $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        return strcmp(is_scalar($a) ? (string) $a : '', is_scalar($b) ? (string) $b : '');
    }
}
