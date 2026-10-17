<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets;

use Closure;
use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Tickets\Accessors\Categories;
use RoundlyConsulting\Tickets\Accessors\Fields;
use RoundlyConsulting\Tickets\Accessors\Guests;
use RoundlyConsulting\Tickets\Accessors\Tags;
use RoundlyConsulting\Tickets\Actions\Tickets\CreateTicketAction;
use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\DataTransferObjects\CreateTicketData;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Exceptions\TicketNotFoundException;
use RoundlyConsulting\Tickets\Handles\TicketHandle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * The package's public API: the root behind the `Tickets` facade, injectable by its own
 * class-string (a container singleton). Every method is thin — the behaviour lives in the
 * actions, each resolved through the container on every call, so host overrides and
 * `Tickets::fake()` apply.
 *
 * Not final on purpose: TicketsFake extends it, so an injected manager still type-checks
 * under `Tickets::fake()`.
 */
class TicketsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * A fluent builder for a new ticket: `Tickets::make()->subject(…)->requester($user)->create()`.
     */
    public function make(): TicketBuilder
    {
        return new TicketBuilder($this);
    }

    /**
     * File a ticket from a DTO — the same code path as the builder.
     */
    public function create(CreateTicketData $data): Ticket
    {
        return $this->perform(
            TicketOperation::Create,
            CreateTicketAction::class,
            static fn (CreateTicketAction $action): Ticket => $action->execute($data),
            ['data' => $data],
        );
    }

    /**
     * The live ticket with this reference (`SUP-42`), or null.
     */
    public function find(string $reference): ?Ticket
    {
        return TicketModels::ticket()::query()->where('reference', $reference)->first();
    }

    /**
     * The live ticket with this reference, or a {@see TicketNotFoundException}.
     */
    public function findOrFail(string $reference): Ticket
    {
        return $this->find($reference) ?? throw TicketNotFoundException::forReference($reference);
    }

    /**
     * A handle scoped to one ticket, by model or by reference (`Tickets::for('SUP-42')`).
     * An unknown or soft-deleted reference throws {@see TicketNotFoundException}.
     */
    public function for(Ticket|string $ticket): TicketHandle
    {
        return new TicketHandle($this, is_string($ticket) ? $this->findOrFail($ticket) : $ticket);
    }

    /**
     * Categories: `make`, `create`, `update`, `move`, `delete`, `restore`, `find`, `tree`.
     */
    public function categories(): Categories
    {
        return new Categories($this);
    }

    /**
     * Custom field definitions: `make`, `define`, `update`, `delete`, `restore`, `prune`, `for`.
     */
    public function fields(): Fields
    {
        return new Fields($this);
    }

    /**
     * Tags: `create`, `findOrCreate`, `rename`, `recolor`, `update`, `delete`, `all`.
     */
    public function tags(): Tags
    {
        return new Tags($this);
    }

    /**
     * Guest requesters: `resolve`, `update`, `convert`.
     */
    public function guests(): Guests
    {
        return new Guests($this);
    }

    /**
     * Run one state-changing operation. Every handle, sub-accessor, builder and model
     * method funnels its terminal call through here, resolving the action from the
     * container, so host overrides apply and the fake records the call.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(TicketOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        return $execute($this->container->make($action));
    }
}
