<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * An internal note was added — agent-only content: never show the comment to a requester.
 * Dispatched after the transaction commits.
 */
final readonly class TicketNoteAdded implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $ticket,
        public Comment $comment,
        public ?Model $actor,
    ) {}

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }
}
