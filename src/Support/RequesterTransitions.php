<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The transitions the requester side may run: `tickets.authorization.requester_transitions`,
 * for the ticket's requester — and, with `participants_can_transition`, for a watcher who may
 * view it. The PHP API trusts its caller; the policy and the guest endpoints ask this.
 *
 * @internal
 */
final class RequesterTransitions
{
    /**
     * Whether `$model` may run `$transition` (null: any requester transition at all).
     */
    public static function allows(Model $model, Ticket $ticket, ?string $transition = null): bool
    {
        if (! self::onRequesterSide($model, $ticket)) {
            return false;
        }

        $allowed = TicketsConfig::requesterTransitions();

        return $transition === null ? $allowed !== [] : in_array($transition, $allowed, true);
    }

    /**
     * The requester, or a viewing watcher when participants may transition.
     */
    private static function onRequesterSide(Model $model, Ticket $ticket): bool
    {
        if (TicketVisibility::isRequester($model, $ticket)) {
            return true;
        }

        return TicketsConfig::participantsCanTransition()
            && TicketsConfig::watchersCanView()
            && TicketVisibility::isWatcher($model, $ticket);
    }
}
