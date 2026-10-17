<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\TicketGuest;

/**
 * A guest registered: their tickets and watches now belong to the user. Dispatched after the transaction commits.
 */
final readonly class GuestConverted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public TicketGuest $guest,
        public Model $user,
        public int $tickets,
        public ?Model $actor,
    ) {}
}
