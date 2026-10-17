<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use RoundlyConsulting\Comments\Events\CommentMentioned;
use RoundlyConsulting\Tickets\Actions\Internal\AutoWatchAction;
use RoundlyConsulting\Tickets\Events\TicketMentioned;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Comments fires `CommentMentioned` for every approved comment — internal notes included —
 * and only when the host set `comments.mention_resolver`. For a ticket comment this turns it
 * into `TicketMentioned`, only for someone who may see that comment: an agent the agent scope
 * lets work on the ticket for an internal note, anyone who can view the ticket for a public
 * reply. Comments of other subjects pass
 * straight through.
 *
 * @internal
 */
final readonly class OnCommentMentioned
{
    public function __construct(
        private AutoWatchAction $watch,
        private Dispatcher $events,
    ) {}

    public function handle(CommentMentioned $event): void
    {
        $mention = $event->mention;
        $comment = $mention->comment;
        $mentioned = $mention->mentionable;

        if ($mentioned === null) {
            return;
        }

        $ticket = $comment->commentable;

        if (! $ticket instanceof Ticket || ! is_a($ticket, TicketModels::ticket())) {
            return;
        }

        $mayRead = TicketComments::isInternal($comment)
            ? TicketVisibility::isAgentFor($mentioned, $ticket)
            : TicketVisibility::canView($mentioned, $ticket);

        if (! $mayRead) {
            return;
        }

        if (TicketsConfig::autoWatch('mentioned')) {
            $this->watch->execute($ticket, $mentioned);
        }

        $this->events->dispatch(new TicketMentioned($ticket, $comment, $mentioned));
    }
}
