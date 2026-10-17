<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;

/** @extends Factory<TicketActivity> */
final class TicketActivityFactory extends Factory
{
    protected $model = TicketActivity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'type' => ActivityType::Updated,
            'is_system' => false,
            'visibility' => ActivityVisibility::Public,
            'properties' => ['changed' => ['subject']],
            'occurred_at' => now(),
        ];
    }
}
