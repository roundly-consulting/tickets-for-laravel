<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use BackedEnum;
use InvalidArgumentException;
use RoundlyConsulting\Tickets\Enums\NotificationType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\EnumLabels;

/**
 * A ticket was resolved, closed or reopened (`TicketResolved`, `TicketClosed`,
 * `TicketReopened`) — sent to the requester and the watchers. A ticket closed by a merge is
 * told by {@see TicketMergedNotification} instead.
 */
class TicketStatusChangedNotification extends TicketNotification
{
    public const string RESOLVED = 'resolved';

    public const string CLOSED = 'closed';

    public const string REOPENED = 'reopened';

    /**
     * @param  string  $change  {@see self::RESOLVED}, {@see self::CLOSED} or {@see self::REOPENED}
     */
    public function __construct(
        Ticket $ticket,
        public string $change,
    ) {
        if (! in_array($change, [self::RESOLVED, self::CLOSED, self::REOPENED], true)) {
            throw new InvalidArgumentException("Unknown status change [{$change}].");
        }

        parent::__construct($ticket);
    }

    public function type(): NotificationType
    {
        return NotificationType::StatusChanged;
    }

    protected function subject(): string
    {
        return self::line('status_changed.'.$this->change.'.subject', ['reference' => $this->ticket->reference, 'subject' => $this->ticket->subject]);
    }

    /**
     * @return list<string>
     */
    protected function lines(object $notifiable): array
    {
        $status = $this->ticket->status;

        return [
            self::line('status_changed.'.$this->change.'.line', ['reference' => $this->ticket->reference, 'subject' => $this->escapedSubject()]),
            ...($status instanceof BackedEnum ? [self::line('status_changed.status', ['status' => EnumLabels::of($status)])] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        $status = $this->ticket->status;

        return ['change' => $this->change, 'status' => $status instanceof BackedEnum ? $status->value : null];
    }
}
