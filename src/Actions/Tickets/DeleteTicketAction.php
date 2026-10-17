<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketDeleted;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a ticket. It keeps its reference (numbers are never reused) and becomes
 * read-only until restored; lifecycle pauses its schedules (auto-close).
 */
final readonly class DeleteTicketAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $ticket->delete();

            $this->record->execute($ticket, ActivityType::Deleted, $actor, [], $system);
            $this->events->dispatch(new TicketDeleted($ticket, $actor));

            return $ticket;
        });
    }
}
