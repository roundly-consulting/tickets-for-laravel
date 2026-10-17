<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Concerns\RequestsTickets;
use RoundlyConsulting\Tickets\Contracts\Assignee;

/**
 * A host user keyed by uuid — the people of a uuid installation.
 *
 * @property string $id
 * @property string|null $name
 * @property string|null $email
 * @property bool $is_agent
 */
final class UuidUser extends Authenticatable implements Assignee
{
    use HasUuids;
    use IsTicketAssignee;
    use Notifiable;
    use RequestsTickets;

    protected $table = 'uuid_users';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_agent' => 'boolean'];
    }
}
