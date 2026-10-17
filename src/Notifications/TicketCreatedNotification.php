<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;

/**
 * The requester's receipt for a new ticket, with its reference — sent even when the requester
 * filed it themselves (it is the receipt), to the requester only: agents learn of new tickets
 * from `TicketCreated`.
 */
class TicketCreatedNotification extends TicketNotification
{
    public function type(): NotificationType
    {
        return NotificationType::Created;
    }

    protected function subject(): string
    {
        return self::line('created.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('created.line', ['reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            self::line('created.follow_up', ['reference' => $this->ticket->reference]),
        ];
    }
}
