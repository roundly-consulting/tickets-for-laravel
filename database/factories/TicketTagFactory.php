<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\TicketTag;

/** @extends Factory<TicketTag> */
final class TicketTagFactory extends Factory
{
    protected $model = TicketTag::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->word().'-'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'color' => $this->faker->hexColor(),
        ];
    }
}
