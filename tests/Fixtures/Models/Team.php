<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * A team assignee: notifies its members.
 *
 * @property int $id
 * @property string $name
 */
final class Team extends Model implements Assignee
{
    protected $table = 'teams';

    protected $guarded = [];

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user');
    }

    public function ticketAssigneeName(): string
    {
        return $this->name;
    }

    /**
     * @return iterable<User>
     */
    public function ticketNotifiables(): iterable
    {
        return $this->members()->get();
    }

    public function isEligibleTicketAssignee(Ticket $ticket): bool
    {
        return true;
    }
}
