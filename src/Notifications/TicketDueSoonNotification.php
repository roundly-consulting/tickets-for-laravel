<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * A ticket came within `tickets.due.soon_window` of its due date (`TicketDueSoon`) — sent to
 * whoever the assignee names; an unassigned ticket notifies no one.
 */
class TicketDueSoonNotification extends DueNotification
{
    public function type(): NotificationType
    {
        return NotificationType::DueSoon;
    }

    protected function subject(): string
    {
        return self::line('due_soon.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [self::line('due_soon.line', ['reference' => $this->ticket->reference, 'subject' => $this->escapedSubject(), 'due' => $this->due()])];
    }
}
