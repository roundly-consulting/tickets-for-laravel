<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * The help desk answered in public (an agent, or a system message) — sent to the requester and
 * the watchers who are not agents. Never sent for an internal note or a held reply.
 */
class TicketRepliedNotification extends CommentNotification
{
    public function type(): NotificationType
    {
        return NotificationType::Replied;
    }

    protected function subject(): string
    {
        return self::line('replied.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('replied.line', ['reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            $this->excerpt(),
        ];
    }
}
