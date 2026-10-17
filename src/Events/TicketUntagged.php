<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketTag;

/**
 * Tags were taken off the ticket — only the ones that changed. Dispatched after the transaction commits.
 */
final readonly class TicketUntagged implements ShouldDispatchAfterCommit, TicketEvent
{
    /**
     * @param  list<TicketTag>  $tags
     */
    public function __construct(
        public Ticket $ticket,
        public array $tags,
        public ?Model $actor,
    ) {}

    public function ticket(): Ticket
    {
        return $this->ticket;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }
}
