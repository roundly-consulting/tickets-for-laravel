<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * Tickets outside every real sequence: scope `factory`, references `FAC-n`, so a factory
 * row never collides with a number the allocator hands out. Created in the lifecycle's
 * initial state unless a state says otherwise.
 *
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    /** @var class-string<Ticket> */
    protected $model = Ticket::class;

    private static int $number = 0;

    /**
     * Build the model the host configured in `tickets.models.ticket`, not the packaged one,
     * so a host subclass comes out of the factory with its own events and observers.
     *
     * @return class-string<Ticket>
     */
    public function modelName(): string
    {
        return TicketModels::ticket();
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = ++self::$number;
        $guest = TicketModels::guest();

        return [
            'reference' => 'FAC-'.$number,
            'number' => $number,
            'sequence_scope' => 'factory',
            'subject' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'priority' => 'normal',
            'requester_type' => (new $guest)->getMorphClass(),
            'requester_id' => TicketGuest::factory(),
            'channel' => 'api',
            'last_activity_at' => now(),
        ];
    }

    public function requestedBy(Model $requester): self
    {
        return $this->state([
            'requester_type' => $requester->getMorphClass(),
            'requester_id' => $requester->getKey(),
        ]);
    }

    public function assignedTo(Model $assignee): self
    {
        return $this->state([
            'assignee_type' => $assignee->getMorphClass(),
            'assignee_id' => $assignee->getKey(),
            'assigned_at' => now(),
        ]);
    }

    public function inProject(Model $project): self
    {
        return $this->state([
            'project_type' => $project->getMorphClass(),
            'project_id' => $project->getKey(),
        ]);
    }

    /**
     * A ticket created directly in `$status` (a state value of the configured lifecycle).
     */
    public function inStatus(string $status): self
    {
        return $this->state(['status' => $status]);
    }
}
