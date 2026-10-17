<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Guests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\BumpGuestAccessAction;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Invalidates every guest link to the ticket at once: its `guest_access_version` moves on, so a
 * link that carries the old one is refused (403 `guest_link_invalid`). Records an internal
 * `guest_access_revoked` activity and fires `GuestAccessRevoked` after commit. A merge revokes
 * the source's links the same way, and so does closing a ticket with
 * `tickets.guests.revoke_on_close`.
 */
final readonly class RevokeGuestAccessAction
{
    public function __construct(
        private BumpGuestAccessAction $bump,
    ) {}

    public function execute(Ticket $ticket, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $this->bump->execute($ticket, $actor, $system);

            return $ticket;
        });
    }
}
