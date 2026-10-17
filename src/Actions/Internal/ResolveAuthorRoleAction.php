<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * The role a message author holds on the ticket, computed when the message is posted: no
 * author is the system, the requester is the requester, someone the agent ability allows —
 * and the agent scope lets work on this ticket — is an agent, and a watcher (with `tickets.watchers.can_view`) is a participant. Anyone else is
 * refused.
 *
 * @internal
 */
final readonly class ResolveAuthorRoleAction
{
    public function execute(Ticket $ticket, ?Model $author): AuthorRole
    {
        return match (true) {
            $author === null => AuthorRole::System,
            TicketVisibility::isRequester($author, $ticket) => AuthorRole::Requester,
            TicketVisibility::isAgentFor($author, $ticket) => AuthorRole::Agent,
            TicketsConfig::watchersCanView() && TicketVisibility::isWatcher($author, $ticket) => AuthorRole::Participant,
            default => throw TicketAccessDeniedException::notAParticipant($ticket),
        };
    }
}
