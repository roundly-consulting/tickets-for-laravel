<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use BackedEnum;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketPriorityChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Re-prioritises a ticket. The same priority again is a no-op: no activity, no event.
 */
final readonly class ChangePriorityAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, BackedEnum|string $priority, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);
        $to = TicketInput::priority($priority);

        return TicketTransaction::run($ticket, function () use ($ticket, $to, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $from = $ticket->priority;

            if ($from === $to) {
                return $ticket;
            }

            $ticket->forceFill(['priority' => $to])->save();

            $this->record->execute($ticket, ActivityType::PriorityChanged, $actor, ['from' => $from, 'to' => $to], $system);
            $this->events->dispatch(new TicketPriorityChanged($ticket, $from, $to, $actor));

            return $ticket;
        });
    }
}
