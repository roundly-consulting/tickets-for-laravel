<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Every outstanding guest link to the ticket stopped working: it was revoked by hand, merged
 * away, or closed with `tickets.guests.revoke_on_close`. `$version` is the new
 * `guest_access_version` a fresh link carries.
 */
final readonly class GuestAccessRevoked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public int $version,
        public ?Model $actor,
    ) {}
}
