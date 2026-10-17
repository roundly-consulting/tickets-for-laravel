<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;

/** @extends Factory<TicketLink> */
final class TicketLinkFactory extends Factory
{
    protected $model = TicketLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'related_ticket_id' => Ticket::factory(),
            'type' => LinkType::RelatesTo,
        ];
    }
}
