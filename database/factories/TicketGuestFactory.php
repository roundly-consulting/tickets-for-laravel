<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\TicketGuest;

/**
 * A bare guest row. Its fields live in attributes-for-laravel, so a test that needs them
 * resolves the guest through `Tickets::guests()->resolve()` instead.
 *
 * @extends Factory<TicketGuest>
 */
final class TicketGuestFactory extends Factory
{
    protected $model = TicketGuest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
