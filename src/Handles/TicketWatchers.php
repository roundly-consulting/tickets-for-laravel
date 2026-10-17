<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Handles;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Watchers\AddWatcherAction;
use RoundlyConsulting\Tickets\Actions\Watchers\RemoveWatcherAction;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Exceptions\InvalidWatcherException;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketWatcher;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::for($ticket)->watchers()` — who follows the ticket. Mutators return this accessor,
 * so calls chain; `ticket()` returns the ticket.
 */
final readonly class TicketWatchers
{
    public function __construct(
        private TicketsManager $manager,
        private Ticket $ticket,
        private ?Model $actor = null,
        private bool $system = false,
    ) {}

    public function add(Model $watcher): static
    {
        $this->manager->perform(
            TicketOperation::AddWatcher,
            AddWatcherAction::class,
            fn (AddWatcherAction $action): Ticket => $action->execute($this->ticket, $watcher, $this->actor, $this->system),
            $this->context(['watcher' => $watcher]),
        );

        return $this;
    }

    public function remove(Model $watcher): static
    {
        $this->manager->perform(
            TicketOperation::RemoveWatcher,
            RemoveWatcherAction::class,
            fn (RemoveWatcherAction $action): Ticket => $action->execute($this->ticket, $watcher, $this->actor, $this->system),
            $this->context(['watcher' => $watcher]),
        );

        return $this;
    }

    /**
     * The handle's actor starts watching.
     */
    public function watch(): static
    {
        return $this->add($this->self());
    }

    /**
     * The handle's actor stops watching.
     */
    public function unwatch(): static
    {
        return $this->remove($this->self());
    }

    public function has(Model $watcher): bool
    {
        return TicketVisibility::isWatcher($watcher, $this->ticket);
    }

    /**
     * Every watch row, oldest first, with its watcher loaded.
     *
     * @return Collection<int, TicketWatcher>
     */
    public function all(): Collection
    {
        return $this->ticket->watchers()->with('watcher')->orderBy('id')->get();
    }

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    private function self(): Model
    {
        return ActorResolver::resolve($this->actor, $this->system) ?? throw InvalidWatcherException::noActor();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(array $extra): array
    {
        return ['ticket' => $this->ticket, 'actor' => $this->actor, 'system' => $this->system, ...$extra];
    }
}
