<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\TicketProject;

/**
 * @property int $id
 * @property string $name
 * @property string $key
 */
final class Project extends Model implements TicketProject
{
    protected $table = 'projects';

    protected $guarded = [];

    public function ticketReferencePrefix(): string
    {
        return $this->key;
    }
}
