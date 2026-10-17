<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A link between two tickets was removed: `$ticket` `$type` `$related` as stored. Dispatched after the transaction
 * commits.
 */
final readonly class TicketUnlinked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public Ticket $related,
        public LinkType $type,
        public ?Model $actor,
    ) {}
}
