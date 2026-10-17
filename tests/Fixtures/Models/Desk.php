<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Contracts\Assignee;

/**
 * A queue-like assignee that cannot log in: always eligible under the defaults.
 *
 * @property int $id
 * @property string $name
 */
final class Desk extends Model implements Assignee
{
    use IsTicketAssignee;

    protected $table = 'teams';

    protected $guarded = [];
}
