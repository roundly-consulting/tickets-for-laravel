<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A ticket was merged into another (`TicketMerged`) — sent to the source's requester, whose
 * conversation now continues on the target (they watch it when they did not request it). The
 * button opens the target; a guest gets a fresh link to it, since the source's links were
 * revoked by the merge.
 */
class TicketMergedNotification extends TicketNotification
{
    public function __construct(
        Ticket $ticket,
        public Ticket $target,
    ) {
        parent::__construct($ticket);
    }

    public function type(): NotificationType
    {
        return NotificationType::Merged;
    }

    protected function linkedTicket(): Ticket
    {
        return $this->target;
    }

    protected function subject(): string
    {
        return self::line('merged.subject', ['reference' => $this->ticket->reference, 'target' => $this->target->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        return [
            self::line('merged.line', ['reference' => $this->ticket->reference, 'target' => $this->target->reference, 'subject' => $this->escapedSubject()]),
            self::line('merged.follow_up', ['target' => $this->target->reference]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return ['target' => $this->target->reference];
    }
}
