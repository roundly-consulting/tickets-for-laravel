<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Tickets\Database\Factories\TicketLinkFactory;
use RoundlyConsulting\Tickets\Enums\LinkType;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * A typed link between two tickets. Unique per (ticket, related, type); a symmetric
 * `relates_to` is stored once, with the lower key in `ticket_id`.
 *
 * @property int $id
 * @property int|string $ticket_id
 * @property int|string $related_ticket_id
 * @property LinkType $type
 * @property string|null $created_by_type
 * @property int|string|null $created_by_id
 * @property Carbon|null $created_at
 * @property-read Ticket $ticket
 * @property-read Ticket $related
 * @property-read Model|null $createdBy
 */
final class TicketLink extends Model
{
    /** @use HasFactory<TicketLinkFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ticket_links';

    protected $guarded = [];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketModels::ticket(), 'ticket_id');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(TicketModels::ticket(), 'related_ticket_id');
    }

    /** @return MorphTo<Model, $this> */
    public function createdBy(): MorphTo
    {
        return $this->morphTo('created_by');
    }

    protected static function newFactory(): TicketLinkFactory
    {
        return TicketLinkFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LinkType::class,
        ];
    }
}
