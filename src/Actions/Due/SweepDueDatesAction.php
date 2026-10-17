<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Due;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\DataTransferObjects\SweepResult;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\TicketDueSoon;
use RoundlyConsulting\Tickets\Events\TicketOverdue;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DueFlags;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * The due-date sweep behind `tickets:sweep` (schedule it every five minutes). Over the unresolved
 * tickets, in id-ordered chunks:
 *
 *  - past the due date → `TicketOverdue` and an `overdue` activity entry;
 *  - within `tickets.due.soon_window` of it → `TicketDueSoon`.
 *
 * Each fires exactly once per due date: a compare-and-set on the ticket's flag ({@see DueFlags})
 * lets one of two concurrent sweeps through, and only moving the due date (which clears both
 * flags) re-arms them. A ticket swept late — already past its date — is flagged overdue only.
 * Auto-closing resolved tickets is lifecycle's sweep (`lifecycle:sweep`), not this one.
 */
final readonly class SweepDueDatesAction
{
    /** Tickets per chunk. */
    private const int CHUNK = 200;

    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(): SweepResult
    {
        $now = CarbonImmutable::now()->startOfSecond();

        $overdue = $this->sweep(
            fn (Builder $query): Builder => $query->where('due_at', '<', $now)->whereNull(DueFlags::OVERDUE),
            fn (Ticket $ticket): bool => $this->flagOverdue($ticket, $now),
        );

        $window = TicketsConfig::dueSoonWindow();

        $dueSoon = $window === null ? 0 : $this->sweep(
            fn (Builder $query): Builder => $query->where('due_at', '>=', $now)->where('due_at', '<=', $now->add($window))->whereNull(DueFlags::DUE_SOON),
            fn (Ticket $ticket): bool => $this->flagDueSoon($ticket, $now),
        );

        return new SweepResult($overdue, $dueSoon);
    }

    /**
     * @param  Closure(Builder<Ticket>): Builder<Ticket>  $constrain
     * @param  Closure(Ticket): bool  $flag
     */
    private function sweep(Closure $constrain, Closure $flag): int
    {
        $flagged = 0;

        $constrain(TicketModels::ticket()::query()->unresolved())
            ->chunkById(self::CHUNK, static function (iterable $tickets) use ($flag, &$flagged): void {
                foreach ($tickets as $ticket) {
                    $flagged += $flag($ticket) ? 1 : 0;
                }
            });

        return $flagged;
    }

    private function flagOverdue(Ticket $ticket, CarbonImmutable $now): bool
    {
        return TicketTransaction::run($ticket, function () use ($ticket, $now): bool {
            if (! DueFlags::claim($ticket, DueFlags::OVERDUE, $now) || $ticket->due_at === null) {
                return false;
            }

            $this->record->execute($ticket, ActivityType::Overdue, null, ['due_at' => $ticket->due_at->toIso8601String()], system: true);

            $this->events->dispatch(new TicketOverdue($ticket, $ticket->due_at));

            return true;
        });
    }

    private function flagDueSoon(Ticket $ticket, CarbonImmutable $now): bool
    {
        return TicketTransaction::run($ticket, function () use ($ticket, $now): bool {
            if (! DueFlags::claim($ticket, DueFlags::DUE_SOON, $now) || $ticket->due_at === null) {
                return false;
            }

            $this->events->dispatch(new TicketDueSoon($ticket, $ticket->due_at));

            return true;
        });
    }
}
