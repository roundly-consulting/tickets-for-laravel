<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Tickets\Database\Factories\TicketActivityFactory;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Enums\ActivityVisibility;
use RoundlyConsulting\Tickets\Exceptions\ActivityIsAppendOnlyException;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * One entry of a ticket's append-only activity timeline. `properties` holds the diff —
 * from/to, ids, changed keys — and never a note body or an encrypted value.
 *
 * @property int $id
 * @property int|string $ticket_id
 * @property ActivityType $type
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property bool $is_system
 * @property ActivityVisibility $visibility
 * @property array<string, mixed>|null $properties
 * @property CarbonImmutable $occurred_at
 * @property-read Ticket $ticket
 * @property-read Model|null $actor
 */
final class TicketActivity extends Model
{
    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'ticket_activities';

    protected $guarded = [];

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(TicketModels::ticket(), 'ticket_id');
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo('actor');
    }

    protected static function booted(): void
    {
        // A force-deleted ticket takes its entries with it through the foreign key's cascade,
        // which fires no model event; editing or deleting one entry never happens.
        self::updating(static fn (): never => throw ActivityIsAppendOnlyException::make());
        self::deleting(static fn (): never => throw ActivityIsAppendOnlyException::make());
    }

    protected static function newFactory(): TicketActivityFactory
    {
        return TicketActivityFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ActivityType::class,
            'visibility' => ActivityVisibility::class,
            'is_system' => 'boolean',
            'properties' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
