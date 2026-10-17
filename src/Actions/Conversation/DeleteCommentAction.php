<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Conversation;

use RoundlyConsulting\Comments\CommentsManager;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a message of the ticket's conversation through comments-for-laravel. Scoped to
 * the ticket: a comment of another ticket is refused as not found. A Closed ticket's
 * conversation is read-only.
 *
 * Who may delete (the author or an agent) is the policy's decision.
 */
final readonly class DeleteCommentAction
{
    public function __construct(
        private CommentsManager $comments,
    ) {}

    public function execute(Ticket $ticket, Comment $comment): Comment
    {
        return TicketTransaction::run($ticket, function () use ($ticket, $comment): Comment {
            TicketLocker::lock($ticket);
            TicketComments::assertOn($comment, $ticket);

            if ($ticket->statusCategory() === StatusCategory::Closed) {
                throw TicketClosedException::for($ticket);
            }

            $this->comments->delete($comment);

            return $comment;
        });
    }
}
