<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * An internal note — sent to whoever the assignee names and to the agents watching the
 * ticket, and only to agents the agent scope lets work on it. Never to a requester, a
 * participant or a guest.
 */
class TicketNoteAddedNotification extends CommentNotification
{
    public function type(): NotificationType
    {
        return NotificationType::NoteAdded;
    }

    public function internal(): bool
    {
        return true;
    }

    protected function subject(): string
    {
        return self::line('note_added.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('note_added.line', ['author' => $this->author(), 'reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            $this->excerpt(),
            self::line('internal'),
        ];
    }
}
