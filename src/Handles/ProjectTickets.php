<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::project($project)` — the tickets of one project (a host model implementing
 * {@see TicketProject}), its own reference sequence and its categories, fields and tags (with
 * the global ones a project's tickets also use). A ticket of another project is refused: the
 * scope is a security boundary.
 */
final readonly class ProjectTickets
{
    public function __construct(
        private TicketsManager $manager,
        private Model&TicketProject $project,
    ) {}

    public function project(): Model&TicketProject
    {
        return $this->project;
    }

    /**
     * A ticket builder already in this project — numbered in the project's sequence.
     */
    public function make(): TicketBuilder
    {
        return $this->manager->make()->project($this->project);
    }

    /**
     * The project's tickets, as a query.
     *
     * @return Builder<Ticket>
     */
    public function tickets(): Builder
    {
        return TicketModels::ticket()::query()->inProject($this->project);
    }

    /**
     * The live ticket of this project with the reference, or null — a ticket of another project
     * is not found here.
     */
    public function find(string $reference): ?Ticket
    {
        return $this->tickets()->where('reference', $reference)->first();
    }

    /**
     * A handle on one of the project's tickets (a model or a reference); a ticket of another
     * project throws {@see TicketNotFoundException}.
     */
    public function for(Ticket|string $ticket): TicketHandle
    {
        $reference = is_string($ticket) ? $ticket : $ticket->reference;

        if (! $ticket instanceof Ticket || ! $this->owns($ticket)) {
            $ticket = $this->find($reference) ?? throw TicketNotFoundException::forReference($reference);
        }

        return $this->manager->for($ticket);
    }

    /**
     * The category tree a ticket of this project may use: the global categories and the
     * project's own.
     *
     * @return Collection<int, TicketCategory>
     */
    public function categories(): Collection
    {
        return $this->manager->categories()->tree($this->project);
    }

    /**
     * The tags a ticket of this project may carry: the global ones and the project's own.
     *
     * @return Collection<int, TicketTag>
     */
    public function tags(): Collection
    {
        return $this->manager->tags()->all($this->project);
    }

    /**
     * The custom fields of a ticket of this project — in a category, or in none.
     *
     * @return array<string, FieldDefinition>
     */
    public function fields(TicketCategory|int|null $category = null): array
    {
        [$type, $id] = TicketInput::morph($this->project);

        return Container::getInstance()->make(FieldSetResolver::class)
            ->forScope($type, $id, $category instanceof TicketCategory ? (int) $category->getKey() : $category);
    }

    private function owns(Ticket $ticket): bool
    {
        return $ticket->project_type === $this->project->getMorphClass()
            && (string) $ticket->project_id === (string) $this->project->getKey();
    }
}
