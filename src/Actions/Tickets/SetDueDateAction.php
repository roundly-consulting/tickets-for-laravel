<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketDueDateChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Sets, moves or (with null) clears a ticket's due date. A change clears both sweep flags,
 * so the overdue / due-soon events fire again for the new date. A past date is allowed here
 * (imports); the API requires a future one. The same instant again is a no-op.
 */
final readonly class SetDueDateAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ?CarbonInterface $dueAt, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);
        $to = $dueAt === null ? null : CarbonImmutable::instance($dueAt)->startOfSecond();

        return TicketTransaction::run($ticket, function () use ($ticket, $to, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $from = $ticket->due_at;

            if ($from?->getTimestamp() === $to?->getTimestamp()) {
                return $ticket;
            }

            $ticket->forceFill([
                'due_at' => $to,
                'due_soon_notified_at' => null,
                'overdue_notified_at' => null,
            ])->save();

            $this->record->execute($ticket, ActivityType::DueChanged, $actor, [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ], $system);

            $this->events->dispatch(new TicketDueDateChanged($ticket, $from, $to, $actor));

            return $ticket;
        });
    }
}
