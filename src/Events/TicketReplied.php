<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A public reply was published on the ticket — never fired for an internal note, nor for a
 * reply the host's moderation is holding. Dispatched after the transaction commits.
 */
final readonly class TicketReplied implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public Comment $comment,
        public AuthorRole $role,
        public ?Model $actor,
    ) {}
}
