<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Contracts\Assignee;

/**
 * A host user that takes the package's assignee defaults: eligible when the agent ability
 * allows it.
 *
 * @property int $id
 * @property string|null $name
 * @property bool $is_agent
 */
final class Agent extends Authenticatable implements Assignee
{
    use IsTicketAssignee;

    protected $table = 'users';

    protected $guarded = [];
}
