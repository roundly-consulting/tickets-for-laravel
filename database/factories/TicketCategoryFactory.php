<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\TicketModels;

/** @extends Factory<TicketCategory> */
final class TicketCategoryFactory extends Factory
{
    /** @var class-string<TicketCategory> */
    protected $model = TicketCategory::class;

    /**
     * Build the model the host configured in `tickets.models.category`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<TicketCategory>
     */
    public function modelName(): string
    {
        return TicketModels::category();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => ['en' => ucfirst($this->faker->unique()->word()).' '.$this->faker->unique()->numberBetween(1, 1_000_000)],
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function under(TicketCategory $parent): self
    {
        return $this->state([
            'parent_id' => $parent->getKey(),
            'project_type' => $parent->project_type,
            'project_id' => $parent->project_id,
        ]);
    }

    public function inProject(Model $project): self
    {
        return $this->state([
            'project_type' => $project->getMorphClass(),
            'project_id' => $project->getKey(),
        ]);
    }
}
