<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Conversation;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Actions\Internal\ApplyAutomationAction;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Actions\Internal\ResolveAuthorRoleAction;
use RoundlyConsulting\Tickets\Actions\Internal\WriteTicketCommentAction;
use RoundlyConsulting\Tickets\Actions\Tickets\TransitionTicketAction;
use RoundlyConsulting\Tickets\DataTransferObjects\ReplyData;
use RoundlyConsulting\Tickets\DataTransferObjects\TransitionData;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\AuthorRole;
use RoundlyConsulting\Tickets\Enums\Automation;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Events\TicketFirstResponded;
use RoundlyConsulting\Tickets\Events\TicketReplied;
use RoundlyConsulting\Tickets\Exceptions\TicketClosedException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketLocker;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Posts a public reply, in one transaction with everything it sets off:
 *
 *  - the author's role (requester, agent, watcher as participant, or the system) — anyone
 *    else is refused, and a Closed ticket takes no reply (`TicketClosedException`);
 *  - an optional transition, applied atomically: a denied transition leaves no reply. It
 *    replaces the reply automation — the author chose the next state;
 *  - otherwise, a requester or participant reply runs an automation: a Waiting ticket
 *    resumes (`RequesterReplied`), a Resolved one reopens (`RequesterReopened`, with
 *    `tickets.comments.reply_reopens`);
 *  - the first public agent reply stamps `first_responded_at`, once.
 *
 * A reply the host's moderation holds (a blocklist hold, or a `require_approval` hold with
 * auto-approve off) reaches no one: its activity entry is internal, and it runs no automation,
 * stamps no first response and fires no `TicketReplied`.
 */
final readonly class PostReplyAction
{
    public function __construct(
        private ResolveAuthorRoleAction $roles,
        private WriteTicketCommentAction $write,
        private AutoWatchAction $watch,
        private TransitionTicketAction $transitions,
        private ApplyAutomationAction $automations,
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ReplyData $data, ?TransitionData $transition = null, ?Model $actor = null, bool $system = false): Comment
    {
        $author = ActorResolver::resolve($actor, $system);
        $channel = TicketInput::channel($data->channel);

        return TicketTransaction::run($ticket, function () use ($ticket, $data, $transition, $author, $system, $channel): Comment {
            TicketLocker::lock($ticket);

            $role = $this->roles->execute($ticket, $author);

            if ($ticket->statusCategory() === StatusCategory::Closed) {
                throw TicketClosedException::for($ticket);
            }

            $comment = $this->write->execute($ticket, $author, $role, $data, $channel, internal: false);

            if ($role === AuthorRole::Agent && $author !== null && TicketsConfig::autoWatch('agent_repliers')) {
                $this->watch->execute($ticket, $author);
            }
            $published = $comment->status === CommentStatus::Approved;

            $this->record->execute(
                $ticket,
                ActivityType::Replied,
                $author,
                ['comment' => $comment->getKey(), 'role' => $role->value, ...($published ? [] : ['held' => true])],
                $system,
                $published ? ActivityVisibility::Public : ActivityVisibility::Internal,
            );

            if ($transition !== null) {
                $this->transitions->execute($ticket, $transition, $author, $system);
            } elseif ($published && ($role === AuthorRole::Requester || $role === AuthorRole::Participant)) {
                $this->runAutomation($ticket, $author);
            }

            if (! $published) {
                return $comment;
            }

            if ($role === AuthorRole::Agent) {
                $this->stampFirstResponse($ticket, $comment, $author);
            }

            $this->events->dispatch(new TicketReplied($ticket, $comment, $role, $author));

            return $comment;
        });
    }

    private function runAutomation(Ticket $ticket, ?Model $author): void
    {
        $category = $ticket->statusCategory();

        if ($category === StatusCategory::Waiting) {
            $this->automations->execute($ticket, Automation::RequesterReplied, $author);
        } elseif ($category === StatusCategory::Resolved && TicketsConfig::replyReopens()) {
            $this->automations->execute($ticket, Automation::RequesterReopened, $author);
        }
    }

    /**
     * Compare-and-set: only the reply that finds `first_responded_at` empty stamps it.
     */
    private function stampFirstResponse(Ticket $ticket, Comment $comment, ?Model $author): void
    {
        $now = CarbonImmutable::now();

        $claimed = $ticket->newQueryWithoutScopes()
            ->whereKey($ticket->getKey())
            ->whereNull('first_responded_at')
            ->toBase()
            ->update(['first_responded_at' => $now]) === 1;

        if (! $claimed) {
            return;
        }

        $ticket->setAttribute('first_responded_at', $now)->syncOriginalAttribute('first_responded_at');

        $this->events->dispatch(new TicketFirstResponded($ticket, $comment, $author));
    }
}
