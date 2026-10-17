<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * For the host's user model: the tickets a user requested and the ones assigned to them, and a
 * builder that files a ticket for them — through the manager, so `Tickets::fake()` sees it.
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
     * A ticket builder with this model as the requester: `$user->openTicket()->subject(…)->create()`.
     */
    public function openTicket(): TicketBuilder
    {
        return Container::getInstance()->make(TicketsManager::class)->make()->requester($this);
    }
}
