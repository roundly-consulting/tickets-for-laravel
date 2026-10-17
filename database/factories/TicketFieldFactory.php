<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\TicketModels;

/** @extends Factory<TicketField> */
final class TicketFieldFactory extends Factory
{
    /** @var class-string<TicketField> */
    protected $model = TicketField::class;

    /**
     * Build the model the host configured in `tickets.models.field`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<TicketField>
     */
    public function modelName(): string
    {
        return TicketModels::field();
    }

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
