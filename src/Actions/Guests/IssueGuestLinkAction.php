<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Guests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\GuestLinkGenerator;
use RoundlyConsulting\Tickets\Exceptions\GuestAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketTrashedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\GuestAccess;

/**
 * A temporary link a guest opens the ticket with, from the bound {@see GuestLinkGenerator}. The
 * recipient is the ticket's guest requester (the default) or one of its guest watchers while
 * watchers may view (`tickets.watchers.can_view`); anyone else is refused
 * (`GuestAccessDeniedException`), and so is a deleted ticket. The link carries
 * the current `guest_access_version`, so revoking access voids it.
 */
final readonly class IssueGuestLinkAction
{
    public function __construct(
        private GuestLinkGenerator $links,
    ) {}

    public function execute(Ticket $ticket, ?Model $guest = null): string
    {
        if ($ticket->trashed()) {
            throw TicketTrashedException::for($ticket);
        }

        $recipient = $guest ?? GuestAccess::requester($ticket);

        if ($recipient === null || ! GuestAccess::isRecipient($ticket, $recipient)) {
            throw GuestAccessDeniedException::notARecipient($ticket);
        }

        // The stored version, never a stale copy: a link minted with a revoked version would
        // be dead on arrival.
        $version = (int) $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->toBase()->value('guest_access_version');
        $ticket->setAttribute('guest_access_version', $version)->syncOriginalAttribute('guest_access_version');

        return $this->links->for($ticket, $recipient);
    }
}
