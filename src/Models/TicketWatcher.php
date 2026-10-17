<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Tickets\Database\Factories\TicketWatcherFactory;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * Someone following a ticket: a user, any host model, or a CC'd guest. A hard row, unique
 * per (ticket, watcher) — no soft deletes.
 *
 * @property int $id
 * @property int|string $ticket_id
 * @property string $watcher_type
 * @property int|string $watcher_id
 * @property Carbon|null $created_at
 * @property-read Ticket $ticket
 * @property-read Model $watcher
 */
final class TicketWatcher extends Model
{
    /** @use HasFactory<TicketWatcherFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ticket_watchers';

    protected $guarded = [];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketModels::ticket(), 'ticket_id');
    }

    /** @return MorphTo<Model, $this> */
    public function watcher(): MorphTo
    {
        return $this->morphTo('watcher');
    }

    protected static function newFactory(): TicketWatcherFactory
    {
        return TicketWatcherFactory::new();
    }
}
