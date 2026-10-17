<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A notification about a ticket's due date, from `tickets:sweep`: it names the date it was
 * swept for, and is dropped on delivery once the ticket is resolved or its date has moved.
 */
abstract class DueNotification extends TicketNotification
{
    public function __construct(
        Ticket $ticket,
        public CarbonImmutable $dueAt,
    ) {
        parent::__construct($ticket);
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->ticket->due_at?->getTimestamp() !== $this->dueAt->getTimestamp()
            || ! in_array($this->ticket->statusCategory(), StatusCategory::unresolved(), true)) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /**
     * The due date, in the recipient's locale and the app's time zone — date and time spelled
     * apart (`LL LT`): Carbon's Slovak `LLL` drops the year.
     */
    protected function due(): string
    {
        $timezone = config('app.timezone');

        return $this->dueAt
            ->setTimezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')
            ->settings(['locale' => App::currentLocale()])
            ->isoFormat('LL LT');
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return ['due_at' => $this->dueAt->toIso8601String()];
    }
}
