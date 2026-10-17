<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * Clears custom field values of a ticket — the same as setting them to null, with the same
 * rules: keys of its own field set, `requester_editable` for someone who is not an agent, and
 * a required field cannot be cleared.
 */
final readonly class ForgetFieldValuesAction
{
    public function __construct(
        private SetFieldValuesAction $set,
    ) {}

    /**
     * @param  list<string>  $keys
     */
    public function execute(Ticket $ticket, array $keys, ?Model $actor = null, bool $system = false): Ticket
    {
        return $this->set->execute($ticket, array_fill_keys($keys, null), $actor, $system);
    }
}
