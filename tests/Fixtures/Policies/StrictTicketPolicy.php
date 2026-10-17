<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Policies;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Policies\TicketPolicy;

/**
 * A host's swapped policy: nobody files tickets through the API.
 */
final class StrictTicketPolicy extends TicketPolicy
{
    public function create(Model $user): bool
    {
        return false;
    }
}
