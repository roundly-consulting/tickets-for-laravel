<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketRestored;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Restores a soft-deleted ticket. A live ticket is left alone (no-op).
 */
final readonly class RestoreTicketAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $actor, $system): Ticket {
            TicketLocker::lock($ticket, allowTrashed: true);

            if (! $ticket->trashed()) {
                return $ticket;
            }

            $ticket->restore();

            $this->record->execute($ticket, ActivityType::Restored, $actor, [], $system);
            $this->events->dispatch(new TicketRestored($ticket, $actor));

            return $ticket;
        });
    }
}
