<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Links;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Events\TicketUnlinked;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLinkException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\LinkGraph;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Removes the `$type` link between the two tickets — the one `$ticket` holds outgoing, else the
 * one it holds incoming. A link this ticket is not part of is refused: a handle never reaches
 * another ticket's links.
 */
final readonly class UnlinkTicketsAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Ticket $related, LinkType $type, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $related, $type, $actor, $system): Ticket {
            TicketLocker::lockInOrder($ticket, $related);

            [$from, $to] = LinkGraph::stored($ticket, $related, $type);
            $link = LinkGraph::find($from, $to, $type);

            if ($link === null && ! $type->isSymmetric()) {
                [$from, $to] = [$related, $ticket];
                $link = LinkGraph::find($from, $to, $type);
            }

            if ($link === null) {
                throw InvalidTicketLinkException::notLinked($related->reference, $type);
            }

            $link->delete();

            $this->record->execute($from, ActivityType::Unlinked, $actor, ['link' => $type->value, 'ticket' => $to->reference], $system);
            $this->record->execute($to, ActivityType::Unlinked, $actor, ['link' => $type->inverse(), 'ticket' => $from->reference], $system);

            $this->events->dispatch(new TicketUnlinked($from, $to, $type, $actor));

            return $ticket;
        });
    }
}
