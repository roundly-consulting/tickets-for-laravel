<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Queries;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\TicketComments;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * The fixed relations and counts a ticket response reads — loaded once for a whole page, so a
 * list costs the same few queries however many tickets it holds. The public message count is
 * what a requester may know: visible, approved and not internal (a host making an internal note
 * visible still does not reveal it); an agent's count is every message.
 *
 * @internal
 */
final class TicketEagerLoads
{
    public static function load(Ticket $ticket, Viewer $viewer): Ticket
    {
        return $ticket->load(self::relations())->loadCount(self::counts($viewer));
    }

    /**
     * @return array<int|string, string|Closure>
     */
    public static function relations(): array
    {
        $guest = TicketModels::guest();

        return [
            'category',
            'project',
            'tags',
            'assignee',
            'requester' => static fn (MorphTo $requester): MorphTo => $requester->morphWith([$guest => ['attachedAttributes']]),
            'mergedInto' => static function (BelongsTo $target): void {
                $target->getQuery()->withoutGlobalScope(SoftDeletingScope::class);
            },
        ];
    }

    /**
     * @return array<string, Closure>
     */
    public static function counts(Viewer $viewer): array
    {
        $counts = [
            'comments as public_messages_count' => static function (Builder $comments): void {
                TicketComments::wherePublished($comments);
            },
        ];

        if ($viewer->isAgent()) {
            $counts['comments as messages_count'] = static function (Builder $comments): void {};
        }

        return $counts;
    }
}
