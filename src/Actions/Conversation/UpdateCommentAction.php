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
use RoundlyConsulting\Tickets\Events\TicketCommentUpdated;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Edits a message of the ticket's conversation through comments-for-laravel (which re-runs the
 * host's blocklist and re-syncs mentions). Scoped to the ticket: a comment of another ticket
 * is refused as not found. An internal note stays internal — the edit never touches
 * `visible` or the meta. A Closed ticket's conversation is read-only.
 *
 * The edit leaves a trail: a `comment_edited` activity entry (the comment id and its author's
 * role, never a body) as visible as the message itself, and `TicketCommentUpdated` after the
 * commit.
 *
 * Who may edit (the author or an agent) is the policy's decision.
 */
final readonly class UpdateCommentAction
{
    public function __construct(
        private CommentsManager $comments,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, Comment $comment, string $body, ?Model $actor = null, bool $system = false): Comment
    {
        $actor = ActorResolver::resolve($actor, $system);

        return TicketTransaction::run($ticket, function () use ($ticket, $comment, $body, $actor, $system): Comment {
            TicketLocker::lock($ticket);
            TicketComments::assertOn($comment, $ticket);

            if ($ticket->statusCategory() === StatusCategory::Closed) {
                throw TicketClosedException::for($ticket);
            }

            $updated = $this->comments->update($comment, $body);

            // Judged after the edit: an edit the host's moderation now holds is agent-only.
            $this->record->execute($ticket, ActivityType::CommentEdited, $actor, TicketComments::trail($updated), $system, TicketComments::visibility($updated));
            $this->events->dispatch(new TicketCommentUpdated($ticket, $updated, $actor));

            return $updated;
        });
    }
}
