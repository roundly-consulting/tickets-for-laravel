<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Assignment;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\ApplyAutomationAction;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Events\TicketAssigned;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\Assignees;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Assigns the ticket to a {@see Assignee} — a user, a
 * team, a queue — that is eligible for it. Re-assigning the same assignee is a no-op (no
 * activity, no event). The assignee starts watching (`tickets.watchers.auto.assignee`), and
 * the `Assigned` automation opens a new ticket, in the same transaction.
 */
final readonly class AssignTicketAction
{
    public function __construct(
        private ApplyAutomationAction $automations,
        private AutoWatchAction $watch,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Model $assignee, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $assignee, $actor, $system): Ticket {
            TicketLocker::lock($ticket);
            Assignees::assertAssignable($assignee, $ticket);

            if ($ticket->assignee_type === $assignee->getMorphClass() && (string) $ticket->assignee_id === (string) $assignee->getKey()) {
                return $ticket;
            }

            $from = $ticket->assignee_type === null ? null : ['type' => $ticket->assignee_type, 'id' => $ticket->assignee_id];

            $ticket->forceFill([
                'assignee_type' => $assignee->getMorphClass(),
                'assignee_id' => $assignee->getKey(),
                'assigned_at' => CarbonImmutable::now(),
            ])->save();
            $ticket->setRelation('assignee', $assignee);

            $this->record->execute($ticket, ActivityType::Assigned, $actor, array_filter([
                'from' => $from,
                'to' => ['type' => $assignee->getMorphClass(), 'id' => $assignee->getKey()],
            ], static fn (mixed $value): bool => $value !== null), $system);

            if (TicketsConfig::autoWatch('assignee')) {
                $this->watch->execute($ticket, $assignee);
            }

            $this->events->dispatch(new TicketAssigned($ticket, $assignee, $actor));

            // Assigning a new ticket opens it (the `Assigned` automation; a no-op from any
            // state its transition cannot leave).
            $this->automations->execute($ticket, Automation::Assigned, $actor);

            return $ticket;
        });
    }
}
