<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Conversation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\CommentsManager;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Events\TicketCommentDeleted;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a message of the ticket's conversation through comments-for-laravel. Scoped to
 * the ticket: a comment of another ticket is refused as not found. A Closed ticket's
 * conversation is read-only.
 *
 * The delete leaves a trail: a `comment_deleted` activity entry (the comment id and its
 * author's role, never a body) as visible as the message was, and `TicketCommentDeleted` after
 * the commit.
 *
 * Who may delete (the author or an agent) is the policy's decision.
 */
final readonly class DeleteCommentAction
{
    public function __construct(
        private CommentsManager $comments,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Comment $comment, ?Model $actor = null, bool $system = false): Comment
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $comment, $actor, $system): Comment {
            TicketLocker::lock($ticket);
            TicketComments::assertOn($comment, $ticket);

            if ($ticket->statusCategory() === StatusCategory::Closed) {
                throw TicketClosedException::for($ticket);
            }

            // Judged before the delete: a requester saw the message only if it was public.
            $visibility = TicketComments::visibility($comment);

            $this->comments->delete($comment);

            $this->record->execute($ticket, ActivityType::CommentDeleted, $actor, TicketComments::trail($comment), $system, $visibility);
            $this->events->dispatch(new TicketCommentDeleted($ticket, $comment, $actor));

            return $comment;
        });
    }
}
