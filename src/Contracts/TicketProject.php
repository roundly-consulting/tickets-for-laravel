<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

/**
 * A host model that groups tickets — a project, a product, a client account. Tickets keep a
 * separate reference sequence per project, prefixed with its key (`SUP-42`).
 */
interface TicketProject
{
    /** The reference prefix: an upper-case letter, then up to nine upper-case letters or digits. */
    public function ticketReferencePrefix(): string;
}
