<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketModels;

/** @extends Factory<TicketTag> */
final class TicketTagFactory extends Factory
{
    /** @var class-string<TicketTag> */
    protected $model = TicketTag::class;

    /**
     * Build the model the host configured in `tickets.models.tag`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<TicketTag>
     */
    public function modelName(): string
    {
        return TicketModels::tag();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word().'-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'color' => $this->faker->hexColor(),
        ];
    }
}
