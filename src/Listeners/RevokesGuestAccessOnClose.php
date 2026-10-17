<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\BumpGuestAccessAction;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketStates;

/**
 * With `tickets.guests.revoke_on_close`, a ticket entering a Closed state voids every guest
 * link to it — for any lifecycle, by status category.
 *
 * @internal
 */
trait RevokesGuestAccessOnClose
{
    private function revokeGuestAccessOnClose(BumpGuestAccessAction $bump, Ticket $ticket, BackedEnum|string|null $from, BackedEnum|string $to, ?Model $actor, bool $system): void
    {
        if (TicketStates::category($to) !== StatusCategory::Closed
            || ($from !== null && TicketStates::category($from) === StatusCategory::Closed)
            || ! TicketsConfig::revokeGuestsOnClose()) {
            return;
        }

        $bump->execute($ticket, $actor, $system);
    }
}
