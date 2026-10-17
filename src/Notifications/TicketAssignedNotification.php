<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DisplayName;
use RoundlyConsulting\Tickets\Support\EnumLabels;
use RoundlyConsulting\Tickets\Support\MailText;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * A ticket was assigned — sent to whoever the assignee names in `ticketNotifiables()` (the
 * user itself; a team's members).
 */
class TicketAssignedNotification extends TicketNotification
{
    public function __construct(
        Ticket $ticket,
        public Model $assignee,
    ) {
        parent::__construct($ticket);
    }

    public function type(): NotificationType
    {
        return NotificationType::Assigned;
    }

    protected function subject(): string
    {
        return self::line('assigned.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        $assignee = DisplayName::of($this->assignee);
        $priority = TicketsConfig::priority($this->ticket->priority);

        return [
            self::line('assigned.line', [
                'reference' => $this->ticket->reference,
                'subject' => $this->escapedSubject(),
                'assignee' => $assignee === null ? self::line('someone') : MailText::escape($assignee),
            ]),
            ...($priority === null ? [] : [self::line('assigned.priority', ['priority' => EnumLabels::of($priority)])]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return ['assignee' => DisplayName::of($this->assignee)];
    }
}
