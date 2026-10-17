<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Due;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Tickets\Contracts\DueDateResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\Durations;

/**
 * The default due-date resolver: now plus the `default_due_in` of the ticket's category,
 * or of its nearest ancestor that sets one. Manual dates only — the seam a future SLA layer
 * replaces.
 */
final readonly class CategoryDueDateResolver implements DueDateResolver
{
    public function dueAt(Ticket $ticket): ?CarbonImmutable
    {
        $category = $ticket->category;

        if ($category === null) {
            return null;
        }

        $offset = CategoryTree::nearest($category, static fn (TicketCategory $node): ?string => $node->default_due_in);
        $interval = $offset === null ? null : Durations::parse($offset);

        return $interval === null ? null : CarbonImmutable::now()->add($interval);
    }
}
