<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * One link as seen from a ticket: the other ticket, the stored type, and the label it reads as
 * from here — the type itself on the outgoing end (`blocks`), its inverse on the incoming end
 * (`blocked_by`). A symmetric `relates_to` reads the same from both.
 */
final readonly class TicketLinkView
{
    public const string OUTGOING = 'outgoing';

    public const string INCOMING = 'incoming';

    public function __construct(
        public int $link,
        public Ticket $ticket,
        public LinkType $type,
        public string $label,
        public string $direction,
    ) {}
}
