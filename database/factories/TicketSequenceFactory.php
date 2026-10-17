<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Models\TicketSequence;

/** @extends Factory<TicketSequence> */
final class TicketSequenceFactory extends Factory
{
    protected $model = TicketSequence::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'scope' => 'factory:'.$this->faker->unique()->numberBetween(1, 1_000_000),
            'next_number' => 1,
        ];
    }
}
