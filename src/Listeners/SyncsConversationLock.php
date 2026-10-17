<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Listeners;

use BackedEnum;
use RoundlyConsulting\Tickets\Contracts\ConversationLock;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketStates;

/**
 * The category-driven conversation lock: entering a Closed state locks the ticket's
 * comments, leaving one unlocks them — for every lifecycle, a host's swapped one included,
 * because it reads only status categories (a state hook would live inside the definition,
 * where tickets cannot add one to a host's lifecycle).
 *
 * @internal
 */
trait SyncsConversationLock
{
    private function syncConversationLock(ConversationLock $lock, Ticket $ticket, BackedEnum|string|null $from, BackedEnum|string $to): void
    {
        if (TicketStates::category($to) === StatusCategory::Closed) {
            $lock->lock($ticket);

            return;
        }

        if ($from === null || TicketStates::category($from) === StatusCategory::Closed) {
            $lock->unlock($ticket);
        }
    }
}
