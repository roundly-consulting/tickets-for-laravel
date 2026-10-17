<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Assignment;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;

/**
 * The actor takes the ticket: an assignment to themselves, so they must be an eligible
 * assignee (for a user, by default, an agent). Claiming needs an actor.
 */
final readonly class ClaimTicketAction
{
    public function __construct(
        private AssignTicketAction $assign,
    ) {}

    public function execute(Ticket $ticket, ?Model $actor = null, bool $system = false): Ticket
    {
        $actor = ActorResolver::resolve($actor, $system) ?? throw InvalidAssigneeException::noActor();

        return $this->assign->execute($ticket, $actor, $actor);
    }
}
