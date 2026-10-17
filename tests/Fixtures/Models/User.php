<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Tickets\Concerns\RequestsTickets;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * @property int $id
 * @property string|null $name
 * @property string|null $email
 * @property bool $is_agent
 */
final class User extends Authenticatable implements Assignee
{
    use Notifiable;
    use RequestsTickets;

    protected $table = 'users';

    protected $guarded = [];

    public function ticketAssigneeName(): string
    {
        return (string) $this->name;
    }

    /**
     * @return iterable<User>
     */
    public function ticketNotifiables(): iterable
    {
        return [$this];
    }

    public function isEligibleTicketAssignee(Ticket $ticket): bool
    {
        return $this->is_agent === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_agent' => 'boolean'];
    }
}
