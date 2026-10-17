<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteFieldValuesAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketFieldsChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Sets custom field values of a ticket — keys of its own field set only, each validated
 * against its field; a null clears a value. Someone who is not an agent may only set
 * `requester_editable` fields. An agent (or the system) must leave every required field filled,
 * the agent-only ones a self-service ticket was filed without included. The activity entry and
 * the event name the keys, never a value.
 */
final readonly class SetFieldValuesAction
{
    public function __construct(
        private WriteFieldValuesAction $write,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function execute(Ticket $ticket, array $values, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $values, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $keys = $this->write->execute($ticket, $values, $actor);

            // An agent (or the system) writing fields owes every required one — the agent-only
            // ones a self-service ticket was filed without included.
            if ($actor === null || TicketVisibility::isAgentFor($actor, $ticket)) {
                $this->write->assertRequiredFilled($ticket);
            }

            if ($keys !== []) {
                $this->record->execute($ticket, ActivityType::FieldsChanged, $actor, ['keys' => $keys], $system);
                $this->events->dispatch(new TicketFieldsChanged($ticket, $keys, $actor));
            }

            return $ticket;
        });
    }
}
