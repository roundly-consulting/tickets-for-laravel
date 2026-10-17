<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The sweep's compare-and-set on a ticket's due flags (`overdue_notified_at`,
 * `due_soon_notified_at`): the flag is set only while it is still empty, the due date is still
 * the one the caller read, and the ticket is still live and unresolved. Exactly one caller sees
 * the row change — so concurrent sweeps flag a ticket once, and a sweep that read a due date
 * someone has since moved (which clears both flags) never flags the moved one.
 *
 * @internal
 */
final class DueFlags
{
    public const string OVERDUE = 'overdue_notified_at';

    public const string DUE_SOON = 'due_soon_notified_at';

    /**
     * Whether this call set the flag, for the due date `$ticket` was read with.
     */
    public static function claim(Ticket $ticket, string $flag, CarbonImmutable $now): bool
    {
        $seen = $ticket->getRawOriginal('due_at');

        if ($seen === null) {
            return false;
        }

        $claimed = $ticket->newQueryWithoutScopes()
            ->whereKey($ticket->getKey())
            ->whereNull($flag)
            ->where('due_at', $seen)
            ->whereNull($ticket->getDeletedAtColumn())
            ->whereIn('status', TicketStates::valuesIn(StatusCategory::unresolved()))
            ->toBase()
            ->update([$flag => $now]) === 1;

        if ($claimed) {
            $ticket->setAttribute($flag, $now)->syncOriginalAttribute($flag);
        }

        return $claimed;
    }
}
