<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketWatcherRemoved;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Removes a watcher. Idempotent: someone not watching changes nothing.
 */
final readonly class RemoveWatcherAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Model $watcher, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $watcher, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $removed = TicketWatcher::query()
                ->where('ticket_id', $ticket->getKey())
                ->where('watcher_type', $watcher->getMorphClass())
                ->where('watcher_id', $watcher->getKey())
                ->delete();

            if ($removed === 0) {
                return $ticket;
            }

            $ticket->unsetRelation('watchers');

            $this->record->execute($ticket, ActivityType::WatcherRemoved, $actor, [
                'watcher' => ['type' => $watcher->getMorphClass(), 'id' => $watcher->getKey()],
            ], $system);

            $this->events->dispatch(new TicketWatcherRemoved($ticket, $watcher, $actor));

            return $ticket;
        });
    }
}
