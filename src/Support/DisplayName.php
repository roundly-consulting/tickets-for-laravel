<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Models\TicketGuest;

/**
 * How a person (a requester, an assignee, a message author, a recipient) is named: a guest by
 * its `display` field, an assignee by `ticketAssigneeName()`, anything else by its `name`.
 *
 * @internal
 */
final class DisplayName
{
    public static function of(?Model $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $name = match (true) {
            $model instanceof TicketGuest => $model->displayName(),
            $model instanceof Assignee => $model->ticketAssigneeName(),
            default => $model->getAttribute('name'),
        };

        return is_string($name) && trim($name) !== '' ? $name : null;
    }
}
