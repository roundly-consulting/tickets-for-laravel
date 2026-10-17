<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A file was attached to the ticket. Dispatched after the transaction commits.
 */
final readonly class TicketAttachmentAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Ticket $ticket,
        public Media $media,
        public ?Model $actor,
    ) {}
}
