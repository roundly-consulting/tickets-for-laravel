<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Conversation;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteTicketCommentAction;
use RoundlyConsulting\Tickets\DataTransferObjects\ReplyData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Events\TicketNoteAdded;
use RoundlyConsulting\Tickets\Exceptions\TicketAccessDeniedException;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\Agents;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Adds an internal note: agents (or the system) only. Defence in depth — the comment is
 * `visible = false` AND marked `meta.tickets.internal`, so comments' own public reads
 * (`visible()`, `threadedComments`, the public counts) never show it, and the package's own
 * public thread filters on both. Its activity entry is internal and holds the comment id,
 * never the body; it runs no automation and stamps no first response.
 */
final readonly class PostNoteAction
{
    public function __construct(
        private WriteTicketCommentAction $write,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ReplyData $data, ?Model $actor = null, bool $system = false): Comment
    {
        $author = ActorResolver::resolve($actor, $system);
        $channel = TicketInput::channel($data->channel);

        return TicketTransaction::run($ticket, function () use ($ticket, $data, $author, $system, $channel): Comment {
            TicketLocker::lock($ticket);

            $role = match (true) {
                $author === null => AuthorRole::System,
                Agents::is($author) => AuthorRole::Agent,
                default => throw TicketAccessDeniedException::notAnAgent($ticket),
            };

            if ($ticket->statusCategory() === StatusCategory::Closed) {
                throw TicketClosedException::for($ticket);
            }

            $comment = $this->write->execute($ticket, $author, $role, $data, $channel, internal: true);
            $published = $comment->status === CommentStatus::Approved;

            $this->record->execute(
                $ticket,
                ActivityType::NoteAdded,
                $author,
                ['comment' => $comment->getKey(), ...($published ? [] : ['held' => true])],
                $system,
            );

            if ($published) {
                $this->events->dispatch(new TicketNoteAdded($ticket, $comment, $author));
            }

            return $comment;
        });
    }
}
