<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;

/**
 * Who a guest link may be for: the ticket's guest requester, or — while watchers may view the
 * ticket (`tickets.watchers.can_view`) — one of its guest watchers: the same rule
 * {@see TicketVisibility::canView()} applies to a signed-in watcher. Issuing a link, the guest
 * middleware and every mailed link go through it.
 *
 * @internal
 */
final class GuestAccess
{
    public static function isRecipient(Ticket $ticket, ?Model $model): bool
    {
        return $model instanceof TicketGuest
            && is_a($model, TicketModels::guest())
            && (TicketVisibility::isRequester($model, $ticket)
                || (TicketsConfig::watchersCanView() && TicketVisibility::isWatcher($model, $ticket)));
    }

    /**
     * The guest with the key, once it is known to be a recipient of the ticket's links — the
     * requester, or a watcher row while watchers may view; null for anyone else.
     */
    public static function recipient(Ticket $ticket, string $key): ?TicketGuest
    {
        $guest = TicketModels::guest();
        $morph = (new $guest)->getMorphClass();

        $linked = ($ticket->requester_type === $morph && (string) $ticket->requester_id === $key)
            || (TicketsConfig::watchersCanView() && TicketWatcher::query()
                ->where('ticket_id', $ticket->getKey())
                ->where('watcher_type', $morph)
                ->where('watcher_id', $key)
                ->exists());

        return $linked ? $guest::query()->find($key) : null;
    }

    /**
     * The ticket's guest requester, when its requester is a guest.
     */
    public static function requester(Ticket $ticket): ?TicketGuest
    {
        $guest = TicketModels::guest();

        return $ticket->requester_type === (new $guest)->getMorphClass()
            ? $guest::query()->find($ticket->requester_id)
            : null;
    }
}
