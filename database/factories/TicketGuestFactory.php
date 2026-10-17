<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * A bare guest row. Its fields live in attributes-for-laravel, so a test that needs them
 * resolves the guest through `Tickets::guests()->resolve()` instead.
 *
 * @extends Factory<TicketGuest>
 */
final class TicketGuestFactory extends Factory
{
    /** @var class-string<TicketGuest> */
    protected $model = TicketGuest::class;

    /**
     * Build the model the host configured in `tickets.models.guest`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<TicketGuest>
     */
    public function modelName(): string
    {
        return TicketModels::guest();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
