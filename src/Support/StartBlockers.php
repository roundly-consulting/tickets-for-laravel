<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketLink;

/**
 * The reads behind `tickets.links.blockers_prevent_start`: what counts as starting a ticket
 * (leaving a non-Active category for the Active one) and which tickets still block it (an
 * unresolved, not trashed ticket with a `blocks` link onto it).
 *
 * @internal
 */
final class StartBlockers
{
    /**
     * Whether moving from `$from` to `$to` starts the ticket while the guard is on.
     */
    public static function guards(BackedEnum|string|null $from, BackedEnum|string $to): bool
    {
        return TicketsConfig::blockersPreventStart()
            && TicketStates::category($to) === StatusCategory::Active
            && TicketStates::category($from) !== StatusCategory::Active;
    }

    /**
     * Whether the named transition would start the ticket from its current state while
     * unresolved tickets block it — the read `can()`, `allowedTransitions()` and automations
     * use; the transition itself decides again under the ticket lock.
     */
    public static function refuse(Ticket $ticket, string $transition): bool
    {
        if (! TicketsConfig::blockersPreventStart()) {
            return false;
        }

        $definition = TicketStates::definition();
        $target = $definition->transition($transition)?->to;

        return $target !== null
            && self::guards($ticket->status, $definition->value($target))
            && self::of($ticket) !== [];
    }

    /**
     * The references of the unresolved, not trashed tickets that block `$ticket`, in key order.
     *
     * @return list<string>
     */
    public static function of(Ticket $ticket): array
    {
        $query = TicketModels::ticket()::query();
        $key = $query->qualifyColumn($query->getModel()->getKeyName());

        return array_values($query
            ->whereIn($key, TicketLink::query()
                ->select('ticket_id')
                ->where('related_ticket_id', $ticket->getKey())
                ->where('type', LinkType::Blocks->value))
            ->unresolved()
            ->orderBy($key)
            ->pluck('reference')
            ->map(static fn (mixed $reference): string => (string) $reference)
            ->all());
    }
}
