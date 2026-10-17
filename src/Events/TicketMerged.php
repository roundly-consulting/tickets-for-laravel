<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketEvent;
use RoundlyConsulting\Tickets\DataTransferObjects\MergeResult;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A ticket was merged into another. Dispatched after the transaction commits.
 */
final readonly class TicketMerged implements ShouldDispatchAfterCommit, TicketEvent
{
    public function __construct(
        public Ticket $source,
        public Ticket $target,
        public MergeResult $result,
        public ?Model $actor,
    ) {}

    public function ticket(): Ticket
    {
        return $this->source;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }
}
