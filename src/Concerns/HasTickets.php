<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Tickets\Builders\TicketBuilder;
use RoundlyConsulting\Tickets\Contracts\TicketProject;
use RoundlyConsulting\Tickets\Handles\ProjectTickets;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Models\TicketTag;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * For the host's project model (one implementing {@see TicketProject}): the project's tickets,
 * categories, fields and tags as relations, and the project-scoped API — every call goes through
 * the manager, so `Tickets::fake()` sees it.
 *
 * @mixin Model
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements TicketProject
 */
trait HasTickets
{
    /** @return MorphMany<Ticket, $this> */
    public function tickets(): MorphMany
    {
        return $this->morphMany(TicketModels::ticket(), 'project');
    }

    /** @return MorphMany<TicketCategory, $this> */
    public function ticketCategories(): MorphMany
    {
        return $this->morphMany(TicketModels::category(), 'project');
    }

    /** @return MorphMany<TicketField, $this> */
    public function ticketFields(): MorphMany
    {
        return $this->morphMany(TicketModels::field(), 'project');
    }

    /** @return MorphMany<TicketTag, $this> */
    public function ticketTags(): MorphMany
    {
        return $this->morphMany(TicketModels::tag(), 'project');
    }

    /**
     * `Tickets::project($this)`.
     */
    public function ticketing(): ProjectTickets
    {
        return Container::getInstance()->make(TicketsManager::class)->project($this);
    }

    /**
     * A ticket builder in this project: `$project->openTicket()->subject(…)->requester($user)->create()`.
     */
    public function openTicket(): TicketBuilder
    {
        return $this->ticketing()->make();
    }
}
