<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tickets\Actions\Tickets\ChangeCategoryAction;
use RoundlyConsulting\Tickets\Actions\Tickets\ChangePriorityAction;
use RoundlyConsulting\Tickets\Actions\Tickets\ChangeRequesterAction;
use RoundlyConsulting\Tickets\Actions\Tickets\DeleteTicketAction;
use RoundlyConsulting\Tickets\Actions\Tickets\RestoreTicketAction;
use RoundlyConsulting\Tickets\Actions\Tickets\SetDueDateAction;
use RoundlyConsulting\Tickets\Actions\Tickets\TransitionTicketAction;
use RoundlyConsulting\Tickets\Actions\Tickets\UpdateTicketAction;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\TransitionData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTicketData;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketLifecycleException;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)` — everything scoped to one ticket.
 *
 * Immutable: `by($actor)` and `asSystem()` return a new handle. A mutator returns the
 * handle, so calls chain (`->prioritize('urgent')->due(now()->addDay())`); a creator or
 * query returns its result. Every action updates the bound ticket instance in place, so a
 * chained call sees the previous call's effect and `ticket()` returns it current.
 */
final readonly class TicketHandle
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    /**
     * Attribute the following calls to `$actor` (null = the authenticated user).
     */
    public function by(?Model $actor): self
    {
        return new self($this->manager, $this->ticket, $actor);
    }

    /**
     * Run the following calls as the system: no actor, no fallback to the authenticated user.
     */
    public function asSystem(): self
    {
        return new self($this->manager, $this->ticket, null, true);
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function update(UpdateTicketData $data): static
    {
        $this->manager->perform(
            TicketOperation::Update,
            UpdateTicketAction::class,
            fn (UpdateTicketAction $action): Ticket => $action->execute($this->ticket, $data, $this->actor, $this->system),
            $this->context(['data' => $data]),
        );

        return $this;
    }

    public function prioritize(BackedEnum|string $priority): static
    {
        $this->manager->perform(
            TicketOperation::ChangePriority,
            ChangePriorityAction::class,
            fn (ChangePriorityAction $action): Ticket => $action->execute($this->ticket, $priority, $this->actor, $this->system),
            $this->context(['priority' => $priority instanceof BackedEnum ? $priority->value : $priority]),
        );

        return $this;
    }

    /**
     * Move the ticket to another category, or out of every category with null.
     */
    public function categorize(TicketCategory|int|null $category): static
    {
        $this->manager->perform(
            TicketOperation::ChangeCategory,
            ChangeCategoryAction::class,
            fn (ChangeCategoryAction $action): Ticket => $action->execute($this->ticket, $category, $this->actor, $this->system),
            $this->context(['category' => $category instanceof TicketCategory ? $category->getKey() : $category]),
        );

        return $this;
    }

    /**
     * Set, move or (with null) clear the due date.
     */
    public function due(?CarbonInterface $at): static
    {
        $this->manager->perform(
            TicketOperation::SetDueDate,
            SetDueDateAction::class,
            fn (SetDueDateAction $action): Ticket => $action->execute($this->ticket, $at, $this->actor, $this->system),
            $this->context(['due_at' => $at]),
        );

        return $this;
    }

    /**
     * Hand the ticket to another requester: a host model, or guest fields.
     */
    public function changeRequester(Model|GuestData $requester): static
    {
        $this->manager->perform(
            TicketOperation::ChangeRequester,
            ChangeRequesterAction::class,
            fn (ChangeRequesterAction $action): Ticket => $action->execute($this->ticket, $requester, $this->actor, $this->system),
            $this->context(['requester' => $requester]),
        );

        return $this;
    }

    public function delete(): static
    {
        $this->manager->perform(
            TicketOperation::Delete,
            DeleteTicketAction::class,
            fn (DeleteTicketAction $action): Ticket => $action->execute($this->ticket, $this->actor, $this->system),
            $this->context(),
        );

        return $this;
    }

    public function restore(): static
    {
        $this->manager->perform(
            TicketOperation::Restore,
            RestoreTicketAction::class,
            fn (RestoreTicketAction $action): Ticket => $action->execute($this->ticket, $this->actor, $this->system),
            $this->context(),
        );

        return $this;
    }

    /**
     * Apply a transition of the configured lifecycle — the generic path every named
     * convenience below takes.
     *
     * @param  array<string, mixed>  $payload  validated by the transition's rules (`resolution` on `resolve`)
     */
    public function transition(string $transition, ?string $reason = null, array $payload = []): TransitionResult
    {
        $data = new TransitionData($transition, $reason, $payload);

        return $this->manager->perform(
            TicketOperation::Transition,
            TransitionTicketAction::class,
            fn (TransitionTicketAction $action): TransitionResult => $action->execute($this->ticket, $data, $this->actor, $this->system),
            $this->context(['transition' => $transition, 'reason' => $reason]),
        );
    }

    /**
     * Whether the transition is allowed from the current state for this handle's actor.
     */
    public function can(string $transition): bool
    {
        return $this->lifecycle()->can($transition);
    }

    /**
     * The names of the transitions this handle's actor may apply now.
     *
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        return array_map(static fn (AvailableTransition $transition): string => $transition->name, $this->lifecycle()->allowedTransitions());
    }

    /**
     * The current state of the configured lifecycle.
     */
    public function status(): TicketState
    {
        $state = $this->lifecycle()->state();

        return $state instanceof TicketState
            ? $state
            : throw InvalidTicketLifecycleException::broken(TicketsConfig::lifecycle(), Messages::get('lifecycle.state_not_ticket_state', ['state' => TicketStates::stored($state)]));
    }

    // Named conveniences over the default lifecycle's transitions. A host lifecycle without
    // one of these names throws UnknownTicketTransitionException — use transition() there.

    public function start(): static
    {
        $this->transition(TicketLifecycle::START);

        return $this;
    }

    public function wait(): static
    {
        $this->transition(TicketLifecycle::WAIT);

        return $this;
    }

    public function hold(string $reason): static
    {
        $this->transition(TicketLifecycle::HOLD, $reason);

        return $this;
    }

    public function resume(): static
    {
        $this->transition(TicketLifecycle::RESUME);

        return $this;
    }

    public function resolve(?string $resolution = null): static
    {
        $this->transition(TicketLifecycle::RESOLVE, payload: $resolution === null ? [] : ['resolution' => $resolution]);

        return $this;
    }

    public function reopen(?string $reason = null): static
    {
        $this->transition(TicketLifecycle::REOPEN, $reason);

        return $this;
    }

    public function close(?string $reason = null): static
    {
        $this->transition(TicketLifecycle::CLOSE, $reason);

        return $this;
    }

    public function dismiss(string $reason): static
    {
        $this->transition(TicketLifecycle::DISMISS, $reason);

        return $this;
    }

    /**
     * The activity timeline, oldest first — public entries only unless `$internal`.
     *
     * @return Collection<int, TicketActivity>
     */
    public function activity(bool $internal = true): Collection
    {
        $query = $this->ticket->activities();

        if (! $internal) {
            $query->where('visibility', ActivityVisibility::Public->value);
        }

        return $query->get();
    }

    private function lifecycle(): LifecycleHandle
    {
        $handle = Container::getInstance()->make(LifecycleManager::class)->for($this->ticket, 'status');

        return $this->system ? $handle->asSystem() : $handle->by(ActorResolver::resolve($this->actor));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(array $extra = []): array
    {
        return ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, ...$extra];
    }
}
