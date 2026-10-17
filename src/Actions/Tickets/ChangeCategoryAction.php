<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Tickets;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteFieldValuesAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketCategoryChanged;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Moves a ticket to another category (or, with null, out of every category). The category
 * must be active and global or of the ticket's project. Category defaults are applied at
 * creation only — never again on a move. A field the new category makes required must already
 * hold a value (for a mover who is no agent, only the requester-editable ones), or the move is
 * refused. The same category again is a no-op.
 */
final readonly class ChangeCategoryAction
{
    public function __construct(
        private RecordActivityAction $record,
        private WriteFieldValuesAction $fields,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, TicketCategory|int|null $category, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $category, $actor, $system): Ticket {
            TicketLocker::lock($ticket);

            $to = $category === null ? null : TicketInput::category($category, $ticket->project_type, $ticket->project_id);
            $from = $ticket->category_id;
            $toKey = $to === null ? null : (int) $to->getKey();

            if ($from === $toKey) {
                return $ticket;
            }

            $ticket->forceFill(['category_id' => $toKey])->save();
            $ticket->unsetRelation('category');

            // The new category may bring required fields the ticket has no value for.
            $this->fields->assertRequiredFilled($ticket, $actor);

            $this->record->execute($ticket, ActivityType::CategoryChanged, $actor, ['from' => $from, 'to' => $toKey], $system);
            $this->events->dispatch(new TicketCategoryChanged($ticket, $from, $toKey, $actor));

            return $ticket;
        });
    }
}
