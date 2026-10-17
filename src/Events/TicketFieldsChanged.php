<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Custom field values were set or cleared — the keys only, never a value (an encrypted one
 * included). Dispatched after the transaction commits.
 */
final readonly class TicketFieldsChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public Ticket $ticket,
        public array $keys,
        public ?Model $actor,
    ) {}
}
