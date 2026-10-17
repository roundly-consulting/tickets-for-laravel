<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketWatcherAdded;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Adds a watcher — a user, any host model, or a CC'd guest. Idempotent: someone already
 * watching changes nothing (no activity, no event), except that an automatic watch becomes a
 * chosen one, which a requester hand-over keeps. A non-agent watcher may then view the ticket
 * and reply as a participant (`tickets.watchers.can_view`).
 */
final readonly class AddWatcherAction
{
    public function __construct(
        private AutoWatchAction $watch,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Model $watcher, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $watcher, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            if (! $this->watch->execute($ticket, $watcher, auto: false)) {
                return $ticket;
            }

            $ticket->unsetRelation('watchers');

            $this->record->execute($ticket, ActivityType::WatcherAdded, $actor, [
                'watcher' => ['type' => $watcher->getMorphClass(), 'id' => $watcher->getKey()],
            ], $system);

            $this->events->dispatch(new TicketWatcherAdded($ticket, $watcher, $actor));

            return $ticket;
        });
    }
}
