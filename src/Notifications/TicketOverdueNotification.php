<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * A ticket passed its due date unresolved (`TicketOverdue`) — sent to whoever the assignee
 * names; an unassigned ticket notifies no one (the host listens to the event).
 */
class TicketOverdueNotification extends DueNotification
{
    public function type(): NotificationType
    {
        return NotificationType::Overdue;
    }

    protected function subject(): string
    {
        return self::line('overdue.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [self::line('overdue.line', ['reference' => $this->ticket->reference, 'subject' => $this->escapedSubject(), 'due' => $this->due()])];
    }
}
