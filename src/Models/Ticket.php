<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Comments\Traits\HasComments;
use RoundlyConsulting\Lifecycle\Casts\AsLifecycleState;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\Tickets\Concerns\HasConfigurableKey;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Database\Factories\TicketFactory;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;

/**
 * A support ticket. Its `status` is owned by lifecycle-for-laravel (never written directly),
 * its conversation by comments-for-laravel.
 *
 * Swappable through `tickets.models.ticket`: extend this class and name the subclass there.
 *
 * @property int|string $id
 * @property string $reference
 * @property int $number
 * @property string $sequence_scope
 * @property string $subject
 * @property string|null $description
 * @property (BackedEnum&TicketState)|null $status
 * @property string $priority
 * @property int|null $category_id
 * @property string $requester_type
 * @property int|string $requester_id
 * @property string|null $assignee_type
 * @property int|string|null $assignee_id
 * @property CarbonImmutable|null $assigned_at
 * @property string|null $project_type
 * @property int|string|null $project_id
 * @property string $channel
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $due_soon_notified_at
 * @property CarbonImmutable|null $overdue_notified_at
 * @property CarbonImmutable|null $first_responded_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $last_activity_at
 * @property int|string|null $merged_into_id
 * @property int $guest_access_version
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TicketCategory|null $category
 * @property-read Model $requester
 * @property-read Model|null $assignee
 * @property-read Model|null $project
 * @property-read Ticket|null $mergedInto
 * @property-read Collection<int, TicketTag> $tags
 * @property-read Collection<int, TicketWatcher> $watchers
 * @property-read Collection<int, TicketLink> $links
 * @property-read Collection<int, TicketActivity> $activities
 */
class Ticket extends Model implements LifecycleSubject
{
    use HasComments;
    use HasConfigurableKey;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasLifecycle;
    use SoftDeletes;

    protected $table = 'tickets';

    protected $guarded = [];

    /**
     * @return array<string, class-string<LifecycleDefinition>>
     */
    public function lifecycleDefinitions(): array
    {
        return ['status' => TicketsConfig::lifecycle()];
    }

    /**
     * Tickets bind by their human reference (`SUP-42`).
     */
    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * The stored priority as the configured enum case, or null when the stored value is no
     * longer part of the enum (the host changed it) — read tolerantly, never a 500.
     */
    public function priority(): ?Priority
    {
        $enum = TicketsConfig::priorityEnum();
        $case = $enum::tryFrom($this->priority);

        return $case instanceof Priority ? $case : null;
    }

    /**
     * The status category of the current state, or null before the ticket has a state.
     */
    public function statusCategory(): ?StatusCategory
    {
        return $this->status?->category();
    }

    /** @return BelongsTo<TicketCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketModels::category(), 'category_id');
    }

    /** @return MorphTo<Model, $this> */
    public function requester(): MorphTo
    {
        return $this->morphTo('requester');
    }

    /** @return MorphTo<Model, $this> */
    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee');
    }

    /** @return MorphTo<Model, $this> */
    public function project(): MorphTo
    {
        return $this->morphTo('project');
    }

    /** @return BelongsTo<Ticket, $this> */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(TicketModels::ticket(), 'merged_into_id');
    }

    /** @return BelongsToMany<TicketTag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TicketModels::tag(), 'ticket_tag', 'ticket_id', 'ticket_tag_id');
    }

    /** @return HasMany<TicketWatcher, $this> */
    public function watchers(): HasMany
    {
        return $this->hasMany(TicketWatcher::class, 'ticket_id');
    }

    /** @return HasMany<TicketLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(TicketLink::class, 'ticket_id');
    }

    /**
     * The activity timeline, oldest first. Named `activities` — `history()` belongs to the
     * attributes trait and `lifecycleHistory()` to lifecycle.
     *
     * @return HasMany<TicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'ticket_id')->orderBy('occurred_at')->orderBy('id');
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AsLifecycleState::class,
            'number' => 'integer',
            'guest_access_version' => 'integer',
            'assigned_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'due_soon_notified_at' => 'immutable_datetime',
            'overdue_notified_at' => 'immutable_datetime',
            'first_responded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }
}
