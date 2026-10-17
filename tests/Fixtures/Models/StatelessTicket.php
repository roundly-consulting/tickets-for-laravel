<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * A swapped ticket model that maps its lifecycle under another attribute first.
 */
final class StatelessTicket extends Ticket
{
    public function lifecycleDefinitions(): array
    {
        return ['state' => TicketsConfig::lifecycle(), 'status' => TicketsConfig::lifecycle()];
    }
}
