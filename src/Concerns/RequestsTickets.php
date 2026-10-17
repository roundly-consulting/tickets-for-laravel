<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * For the host's user model: the tickets a user requested, the ones assigned to them, the ones
 * they watch and the ones they may see, and a builder that files a ticket for them — through
 * the manager, so `Tickets::fake()` sees it.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 */
trait RequestsTickets
{
    /** @return MorphMany<Ticket, $this> */
    public function requestedTickets(): MorphMany
    {
        return $this->morphMany(TicketModels::ticket(), 'requester');
    }

    /** @return MorphMany<Ticket, $this> */
    public function assignedTickets(): MorphMany
    {
        return $this->morphMany(TicketModels::ticket(), 'assignee');
    }

    /**
     * The tickets this model watches (`ticket_watchers`) — "my tickets" pages; eager-loadable
     * and countable (`withCount('watchedTickets')`).
     *
     * @return MorphToMany<Ticket, $this>
     */
    public function watchedTickets(): MorphToMany
    {
        return $this->morphToMany(TicketModels::ticket(), 'watcher', 'ticket_watchers', 'watcher_id', 'ticket_id');
    }

    /**
     * The tickets this model may see, as a query — the same as `Tickets::visibleTo($this)`.
     *
     * @return Builder<Ticket>
     */
    public function visibleTickets(): Builder
    {
        return Container::getInstance()->make(TicketsManager::class)->visibleTo($this);
    }

    /**
     * A ticket builder with this model as the requester: `$user->openTicket()->subject(…)->create()`.
     */
    public function openTicket(): TicketBuilder
    {
        return Container::getInstance()->make(TicketsManager::class)->make()->requester($this);
    }
}
