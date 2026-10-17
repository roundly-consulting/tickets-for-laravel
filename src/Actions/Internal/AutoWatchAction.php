<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;

/**
 * Makes `$watcher` watch the ticket unless it already does — returns whether a row was added.
 * An insert that ignores the (ticket, watcher) unique index, so it is idempotent and never
 * aborts the surrounding transaction (Postgres would on a failed insert). Inside the calling
 * action's transaction; the auto-watches of `tickets.watchers.auto` record no activity.
 *
 * `$auto` marks the row automatic; a watch added on purpose (`$auto` false) also turns an
 * existing automatic row into a chosen one, which a requester hand-over then keeps.
 *
 * @internal
 */
final readonly class AutoWatchAction
{
    public function execute(Ticket $ticket, Model $watcher, bool $auto = true): bool
    {
        $key = $watcher->getKey();

        return $this->morph($ticket, $watcher->getMorphClass(), is_int($key) ? $key : (string) $key, $auto);
    }

    /**
     * The same, for a watcher known only by its morph — a merge copies watch rows as stored.
     */
    public function morph(Ticket $ticket, string $type, int|string $key, bool $auto = true): bool
    {
        $added = TicketWatcher::query()->toBase()->insertOrIgnore([
            'ticket_id' => $ticket->getKey(),
            'watcher_type' => $type,
            'watcher_id' => $key,
            'auto' => $auto,
            'created_at' => CarbonImmutable::now(),
        ]) > 0;

        if (! $added && ! $auto) {
            TicketWatcher::query()
                ->where('ticket_id', $ticket->getKey())
                ->where('watcher_type', $type)
                ->where('watcher_id', $key)
                ->where('auto', true)
                ->toBase()
                ->update(['auto' => false]);
        }

        return $added;
    }
}
