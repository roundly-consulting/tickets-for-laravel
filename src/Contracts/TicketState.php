<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Contracts;

use RoundlyConsulting\Tickets\Enums\StatusCategory;

/**
 * Implemented by the backed enum of a ticket lifecycle's states. Every state maps to one of
 * the fixed status categories, which is all the package logic ever reads.
 */
interface TicketState
{
    public function category(): StatusCategory;
}
