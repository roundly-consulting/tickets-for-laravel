<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Tickets\Models\TicketSequence;

/**
 * Named locks on fixed `ticket_sequences` rows. They serialise the writes whose invariant
 * no portable unique index can hold — category cycles and NULL-scoped slugs, global tags,
 * field keys across scopes, link cycles. Taken before any ticket row, so the global lock
 * order is: advisory row → ticket rows (primary-key order) → package rows.
 *
 * Must run inside a transaction: the lock is held until it ends.
 *
 * @internal
 */
final class AdvisoryLock
{
    public const string CATEGORIES = 'lock:categories';

    public const string TAGS = 'lock:tags';

    public const string FIELDS = 'lock:fields';

    public const string LINKS = 'lock:links';

    public static function acquire(string $name): void
    {
        LockedRow::mutex(TicketSequence::query(), ['scope' => $name]);
    }
}
