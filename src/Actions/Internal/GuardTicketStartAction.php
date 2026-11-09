<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use BackedEnum;
use RoundlyConsulting\Tickets\Exceptions\TicketBlockedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\StartBlockers;

/**
 * With `tickets.links.blockers_prevent_start` on, refuses a transition that STARTS the ticket —
 * from a state outside the Active category into one inside it — while an unresolved, not
 * trashed ticket `blocks` it ({@see TicketBlockedException}). Moving between two Active states,
 * and every other transition, passes.
 *
 * Runs inside the transition's transaction, after lifecycle locked the ticket row. A `blocks`
 * link onto the ticket is only ever written with that row locked too, so a start and a
 * concurrent link serialise on it: whichever commits second sees the other. A blocker
 * reopened at the same moment is read as committed — the start simply came first, exactly as
 * if the blocker were reopened a second later.
 *
 * @internal
 */
final readonly class GuardTicketStartAction
{
    public function execute(Ticket $ticket, BackedEnum|string|null $from, BackedEnum|string $to): void
    {
        if (! StartBlockers::guards($from, $to)) {
            return;
        }

        $blockers = StartBlockers::of($ticket);

        if ($blockers !== []) {
            throw TicketBlockedException::byOpenBlockers($ticket, $blockers);
        }
    }
}
