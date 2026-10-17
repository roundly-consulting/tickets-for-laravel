<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;

/**
 * Who may see a ticket: an agent, its requester, or — with `tickets.watchers.can_view` — one of
 * its watchers. One source of truth for every reader that must not show a ticket (or a mention
 * of it) to someone who cannot open it.
 *
 * @internal
 */
final class TicketVisibility
{
    public static function canView(?Model $user, Ticket $ticket): bool
    {
        if ($user === null) {
            return false;
        }

        return Agents::is($user)
            || self::isRequester($user, $ticket)
            || (TicketsConfig::watchersCanView() && self::isWatcher($user, $ticket));
    }

    public static function isRequester(Model $model, Ticket $ticket): bool
    {
        return $ticket->requester_type === $model->getMorphClass()
            && (string) $ticket->requester_id === (string) $model->getKey();
    }

    public static function isWatcher(Model $model, Ticket $ticket): bool
    {
        return TicketWatcher::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('watcher_type', $model->getMorphClass())
            ->where('watcher_id', $model->getKey())
            ->exists();
    }
}
