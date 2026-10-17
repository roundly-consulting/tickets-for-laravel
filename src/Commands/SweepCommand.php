<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Tickets\Support\Messages;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * Flags the unresolved tickets past their due date (`TicketOverdue`) or within
 * `tickets.due.soon_window` of it (`TicketDueSoon`), each once per due date. Schedule it every
 * five minutes; auto-close is lifecycle's `lifecycle:sweep`, scheduled every minute.
 */
final class SweepCommand extends Command
{
    protected $signature = 'tickets:sweep';

    protected $description = 'Flag overdue and due-soon tickets (schedule every five minutes)';

    public function handle(TicketsManager $tickets): int
    {
        $result = $tickets->sweep();

        $this->info(Messages::choice('sweep.overdue', $result->overdue));
        $this->info(Messages::choice('sweep.due_soon', $result->dueSoon));

        return self::SUCCESS;
    }
}
