<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Models\TicketWatcher;

/** @extends Factory<TicketWatcher> */
final class TicketWatcherFactory extends Factory
{
    protected $model = TicketWatcher::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'watcher_type' => (new TicketGuest)->getMorphClass(),
            'watcher_id' => TicketGuest::factory(),
        ];
    }
}
