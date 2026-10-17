<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Someone was `@mentioned` in the ticket's conversation and may see that comment: an internal
 * note reaches agents only, a public reply anyone who can view the ticket. Listen here rather
 * than to comments' `CommentMentioned`, which also fires for internal notes. Dispatched after
 * the transaction commits.
 */
final readonly class TicketMentioned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public Comment $comment,
        public Model $mentioned,
    ) {}
}
