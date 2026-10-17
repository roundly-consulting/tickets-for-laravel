<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Support\TicketComments;

/**
 * Someone was `@mentioned` (`TicketMentioned`) — sent to them unless the message's own
 * notification already reaches them. A mention in an internal note reaches agents only.
 */
class TicketMentionedNotification extends CommentNotification
{
    public function type(): NotificationType
    {
        return NotificationType::Mentioned;
    }

    public function internal(): bool
    {
        return TicketComments::isInternal($this->comment);
    }

    protected function subject(): string
    {
        return self::line('mentioned.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('mentioned.line', ['author' => $this->author(), 'reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            $this->excerpt(),
            ...($this->internal() ? [self::line('internal')] : []),
        ];
    }
}
