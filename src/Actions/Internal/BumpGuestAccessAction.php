<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Events\GuestAccessRevoked;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Moves the ticket's `guest_access_version` on, inside the calling transaction, which
 * invalidates every guest link issued so far: an internal `guest_access_revoked` activity, and
 * `GuestAccessRevoked` after commit. A plain query, not a save — `updated_at` (a public field)
 * must not tell a requester that links were revoked.
 *
 * @internal
 */
final readonly class BumpGuestAccessAction
{
    public function __construct(
        private RecordActivityAction $record,
        private Dispatcher $events,
    ) {}

    public function execute(Ticket $ticket, ?Model $actor, bool $system = false): int
    {
        $row = $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey());
        $row->toBase()->increment('guest_access_version');

        $version = (int) $ticket->newQueryWithoutScopes()->whereKey($ticket->getKey())->toBase()->value('guest_access_version');
        $ticket->setAttribute('guest_access_version', $version)->syncOriginalAttribute('guest_access_version');

        $this->record->execute($ticket, ActivityType::GuestAccessRevoked, $actor, ['version' => $version], $system);
        $this->events->dispatch(new GuestAccessRevoked($ticket, $version, $actor));

        return $version;
    }
}
