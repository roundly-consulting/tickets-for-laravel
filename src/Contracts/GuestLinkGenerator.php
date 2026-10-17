<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Builds the link a guest opens a ticket with. Bound from `tickets.guests.link_generator`; the
 * default signs the package's guest endpoint. A host with its own frontend binds a generator
 * that wraps that signed API URL into a frontend link — the frontend calls the URL as-is.
 *
 * The recipient has already been checked: it is the ticket's guest requester or one of its
 * guest watchers.
 */
interface GuestLinkGenerator
{
    public function for(Ticket $ticket, Model $recipient): string;
}
