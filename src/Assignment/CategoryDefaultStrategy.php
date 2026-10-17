<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Assignment;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\AssignmentStrategy;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\CategoryTree;

/**
 * The default assignment strategy: the `default_assignee` of the ticket's category, or of
 * its nearest ancestor that sets one.
 */
final readonly class CategoryDefaultStrategy implements AssignmentStrategy
{
    public function assigneeFor(Ticket $ticket): ?Model
    {
        $category = $ticket->category;

        if ($category === null) {
            return null;
        }

        return CategoryTree::nearest($category, static fn (TicketCategory $node): ?Model => $node->default_assignee_type === null ? null : $node->defaultAssignee);
    }
}
