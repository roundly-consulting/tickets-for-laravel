<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Models\TicketField;

/** @extends Factory<TicketField> */
final class TicketFieldFactory extends Factory
{
    protected $model = TicketField::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->lexify('field_????????'),
            'label' => ['en' => ucfirst($this->faker->word())],
            'type' => FieldType::Text,
            'required' => false,
            'encrypted' => false,
            'requester_visible' => true,
            'requester_editable' => false,
            'position' => 0,
            'is_active' => true,
        ];
    }
}
