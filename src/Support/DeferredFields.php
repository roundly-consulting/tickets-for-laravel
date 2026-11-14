<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The reads behind `tickets.fields.defer_required_in`: whether a state defers the required
 * custom fields (a draft may be saved with them empty), and whether a move between two states
 * leaves a deferred one for a state that is not — the moment the fields fall due.
 *
 * @internal
 */
final class DeferredFields
{
    /**
     * Whether a state given as a case or its raw stored value defers the required fields.
     */
    public static function in(BackedEnum|string|int|null $state): bool
    {
        if ($state === null) {
            return false;
        }

        $deferred = TicketsConfig::deferRequiredIn();

        $value = $state instanceof BackedEnum ? $state->value : $state;

        return $deferred !== [] && in_array((string) $value, $deferred, true);
    }

    /**
     * Whether the ticket's current state defers its required fields.
     */
    public static function for(Ticket $ticket): bool
    {
        return self::in($ticket->status);
    }

    /**
     * Whether moving from `$from` to `$to` leaves a deferred state for one that is not.
     */
    public static function leaving(BackedEnum|string|null $from, BackedEnum|string $to): bool
    {
        return self::in($from) && ! self::in($to);
    }
}
