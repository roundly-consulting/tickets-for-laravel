<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Comments\Enums\CommentStatus;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DisplayName;
use RoundlyConsulting\Tickets\Support\MailText;
use RoundlyConsulting\Tickets\Support\TicketComments;

/**
 * A notification about one message of the conversation: it quotes the message (escaped, cut
 * at {@see MailText::EXCERPT} characters) and is dropped on delivery when the message was
 * deleted, withdrawn from approval, or — for a public notification — turned internal since.
 */
abstract class CommentNotification extends TicketNotification
{
    public function __construct(
        Ticket $ticket,
        public Comment $comment,
    ) {
        parent::__construct($ticket);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->comment->trashed() || $this->comment->status !== CommentStatus::Approved
            || ! TicketComments::belongsTo($this->comment, $this->ticket)
            || (! $this->internal() && TicketComments::isInternal($this->comment))) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /**
     * The message's author, named (escaped) — or the generic author for a system message or a
     * nameless one.
     */
    protected function author(): string
    {
        $name = DisplayName::of($this->comment->actor);

        return $name === null ? self::line('someone') : MailText::escape($name);
    }

    protected function excerpt(): string
    {
        return MailText::excerpt($this->comment->comment);
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return ['comment' => $this->comment->getKey(), 'author' => DisplayName::of($this->comment->actor)];
    }
}
