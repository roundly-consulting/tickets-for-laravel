<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * The requester (or a watcher, as a participant) replied — sent to whoever the assignee names
 * and to the agents watching the ticket.
 */
class TicketRequesterRepliedNotification extends CommentNotification
{
    public function type(): NotificationType
    {
        return NotificationType::RequesterReplied;
    }

    protected function subject(): string
    {
        return self::line('requester_replied.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('requester_replied.line', ['author' => $this->author(), 'reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            $this->excerpt(),
        ];
    }
}
