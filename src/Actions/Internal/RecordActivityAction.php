<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * Appends one entry to a ticket's timeline, inside the calling action's transaction. The
 * properties hold the diff — never a note body or an encrypted value. Nothing is written
 * with `tickets.activity.enabled` off.
 *
 * @internal
 */
final readonly class RecordActivityAction
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function execute(
        Ticket $ticket,
        ActivityType $type,
        ?Model $actor,
        array $properties = [],
        bool $system = false,
        ?ActivityVisibility $visibility = null,
    ): ?TicketActivity {
        if (! TicketsConfig::activityEnabled()) {
            return null;
        }

        return TicketActivity::query()->create([
            'ticket_id' => $ticket->getKey(),
            'type' => $type,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'is_system' => $system,
            'visibility' => $visibility ?? $type->defaultVisibility(),
            'properties' => $properties === [] ? null : $properties,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
