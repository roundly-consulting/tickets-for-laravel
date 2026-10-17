<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Assignment;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketUnassigned;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Takes the ticket's assignee away. An unassigned ticket stays as it is (no activity, no
 * event). The former assignee keeps watching.
 */
final readonly class UnassignTicketAction
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

            if ($ticket->assignee_type === null) {
                return $ticket;
            }

            $from = ['type' => $ticket->assignee_type, 'id' => $ticket->assignee_id];
            $previous = $ticket->assignee()->first();

            $ticket->forceFill(['assignee_type' => null, 'assignee_id' => null, 'assigned_at' => null])->save();
            $ticket->setRelation('assignee', null);

            $this->record->execute($ticket, ActivityType::Unassigned, $actor, ['from' => $from], $system);
            $this->events->dispatch(new TicketUnassigned($ticket, $previous, $actor));

            return $ticket;
        });
    }
}
