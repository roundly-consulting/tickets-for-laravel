<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Tickets\Concerns\IsTicketAssignee;
use RoundlyConsulting\Tickets\Contracts\Assignee;

/**
 * A stock Laravel user with exactly what the README tells the host to add — no more. The
 * `is_support` column is the host's own agent flag the README's Gate line stands in for.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_support
 */
final class ReadmeUser extends Authenticatable implements Assignee
{
    use IsTicketAssignee;
    use Notifiable;

    protected $table = 'readme_users';

    protected $fillable = ['name', 'email', 'is_support'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_support' => 'boolean'];
    }
}
