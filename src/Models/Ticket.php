<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Attributes\Contracts\HasAttributes as HasAttributesContract;
use RoundlyConsulting\Attributes\Traits\HasAttributes as HasCustomFields;
use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Comments\Traits\HasComments;
use RoundlyConsulting\Lifecycle\Casts\AsLifecycleState;
use RoundlyConsulting\Lifecycle\Concerns\HasLifecycle;
use RoundlyConsulting\Lifecycle\Contracts\LifecycleSubject;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;
use RoundlyConsulting\Tickets\Concerns\HasConfigurableKey;
use RoundlyConsulting\Tickets\Contracts\Priority;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Database\Factories\TicketFactory;
use RoundlyConsulting\Tickets\Enums\StatusCategory;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\FieldFilters;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketStates;
use RoundlyConsulting\Tickets\Support\TicketVisibility;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * A support ticket. Its `status` is owned by lifecycle-for-laravel (never written directly),
 * its conversation by comments-for-laravel, its custom field values by attributes-for-laravel
 * and its attachments by media-library-for-laravel.
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
class Ticket extends Model implements HasAttributesContract, HasMedia, LifecycleSubject
{
    use HasComments;
    use HasConfigurableKey;
    use HasCustomFields;

    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    use HasLifecycle;
    use InteractsWithMedia;
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
     * The custom fields that apply to this ticket — global, its project's, and its category's
     * (ancestors included) — as the raw definitions attributes-for-laravel reads. A ticket's
     * attributes are its custom field values only.
     *
     * @return array<string, array<string, mixed>>
     */
    public function attributeDefinitions(): array
    {
        return app(FieldSetResolver::class)->attributeDefinitions($this);
    }

    /**
     * The ticket's attachments bucket: private, on `tickets.attachments.disk` (`local` by
     * default — never media-library's web-served `public` default), with the configured mime
     * types and size cap.
     */
    public function registerMediaBuckets(): void
    {
        $disk = TicketsConfig::attachmentsDisk();

        $bucket = $this->addMediaBucket(TicketsConfig::attachmentsBucket())
            ->private()
            ->useDisk($disk)
            ->storingVariantsOnDisk($disk)
            ->maxFileSize(TicketsConfig::attachmentMaxKilobytes() * 1024);

        $mimes = TicketsConfig::attachmentMimes();

        if ($mimes !== []) {
            $bucket->acceptsMimeTypes($mimes);
        }
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

    /**
     * Post a public reply as `$author` (null = the authenticated user) — the same call as
     * `Tickets::for($ticket)->by($author)->reply($body)`.
     */
    public function reply(string $body, ?Model $author = null): Comment
    {
        return app(TicketsManager::class)->for($this)->by($author)->reply($body);
    }

    /**
     * Add an internal note as `$author` (null = the authenticated user) — the same call as
     * `Tickets::for($ticket)->by($author)->note($body)`.
     */
    public function note(string $body, ?Model $author = null): Comment
    {
        return app(TicketsManager::class)->for($this)->by($author)->note($body);
    }

    /**
     * Assign the ticket — the same call as `Tickets::for($ticket)->by($actor)->assign($assignee)`.
     */
    public function assignTo(Model $assignee, ?Model $actor = null): static
    {
        app(TicketsManager::class)->for($this)->by($actor)->assign($assignee);

        return $this;
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

    // Query scopes — the API's list filters are built on these, so PHP and HTTP filter alike.

    /**
     * The tickets `$user` may see — exactly the ones the policy's `view` allows: as an agent
     * (narrowed by the agent scope), as the requester, or as a watcher with
     * `tickets.watchers.can_view`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, Model $user): void
    {
        TicketVisibility::constrain($query, $user);
    }

    /**
     * Assigned to any of the models.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAssignedTo(Builder $query, Model ...$assignees): void
    {
        self::whereMorphIn($query, 'assignee', array_values($assignees));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('assignee_type'));
    }

    /**
     * Requested by the model — a host model or a TicketGuest.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRequestedBy(Builder $query, Model $requester): void
    {
        self::whereMorphIn($query, 'requester', [$requester]);
    }

    /**
     * In the project, or — with null — in no project.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInProject(Builder $query, ?Model $project): void
    {
        if ($project === null) {
            $query->whereNull($query->qualifyColumn('project_type'));

            return;
        }

        self::whereMorphIn($query, 'project', [$project]);
    }

    /**
     * In the category, and with `$descendants` in any category below it (a walk bounded by
     * `tickets.categories.max_depth`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeInCategory(Builder $query, TicketCategory|int $category, bool $descendants = false): void
    {
        $id = $category instanceof TicketCategory ? (int) $category->getKey() : $category;

        $query->whereIn($query->qualifyColumn('category_id'), $descendants ? [$id, ...CategoryTree::descendantIds($id)] : [$id]);
    }

    /**
     * In any of the exact states.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithStatus(Builder $query, BackedEnum|string ...$states): void
    {
        $query->whereIn($query->qualifyColumn('status'), array_map(TicketStates::stored(...), $states));
    }

    /**
     * In any state of the categories — mapped through the configured lifecycle's states, so
     * it follows a swapped status enum.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithStatusCategory(Builder $query, StatusCategory ...$categories): void
    {
        $query->whereIn($query->qualifyColumn('status'), TicketStates::valuesIn(array_values($categories)));
    }

    /**
     * Still needing work: Open, Active or Waiting.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('status'), TicketStates::valuesIn(StatusCategory::unresolved()));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWithPriority(Builder $query, BackedEnum|string ...$priorities): void
    {
        $query->whereIn(
            $query->qualifyColumn('priority'),
            array_map(static fn (BackedEnum|string $priority): string|int => $priority instanceof BackedEnum ? $priority->value : $priority, $priorities),
        );
    }

    /**
     * Tagged with any of the tags (by slug or model).
     *
     * @param  Builder<static>  $query
     */
    public function scopeTaggedWith(Builder $query, string|TicketTag ...$tags): void
    {
        $slugs = array_values(array_filter($tags, is_string(...)));
        $ids = array_map(static fn (TicketTag $tag): mixed => $tag->getKey(), array_values(array_filter($tags, static fn (string|TicketTag $tag): bool => $tag instanceof TicketTag)));

        $query->whereHas('tags', static function (Builder $tag) use ($slugs, $ids): void {
            $tag->where(static function (Builder $any) use ($slugs, $ids): void {
                $any->whereIn($any->qualifyColumn('slug'), $slugs)->orWhereIn($any->qualifyColumn('id'), $ids);
            });
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeWatchedBy(Builder $query, Model $watcher): void
    {
        $query->whereHas('watchers', static function (Builder $watch) use ($watcher): void {
            $watch->where('watcher_type', $watcher->getMorphClass())->where('watcher_id', $watcher->getKey());
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDueBefore(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('due_at'), '<', $at);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeDueAfter(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('due_at'), '>', $at);
    }

    /**
     * Due at or after the moment (inclusive — the API's `due_from`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeDueFrom(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('due_at'), '>=', $at);
    }

    /**
     * Due at or before the moment (inclusive — the API's `due_to`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeDueTo(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('due_at'), '<=', $at);
    }

    /**
     * Filed at or after the moment (inclusive — the API's `created_from`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeCreatedFrom(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('created_at'), '>=', $at);
    }

    /**
     * Filed at or before the moment (inclusive — the API's `created_to`).
     *
     * @param  Builder<static>  $query
     */
    public function scopeCreatedTo(Builder $query, CarbonInterface $at): void
    {
        $query->where($query->qualifyColumn('created_at'), '<=', $at);
    }

    /**
     * Came in through any of the channels.
     *
     * @param  Builder<static>  $query
     */
    public function scopeViaChannel(Builder $query, string ...$channels): void
    {
        $query->whereIn($query->qualifyColumn('channel'), array_values($channels));
    }

    /**
     * Has the reference, or holds the text in its subject or description. Wildcards in the
     * term (`%`, `_`) match themselves only; a blank term adds nothing.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $query->where(static function (Builder $any) use ($term): void {
            $any->where($any->qualifyColumn('reference'), mb_strtoupper($term))
                ->whereLikeEscaped($any->qualifyColumn('subject'), $term, 'or')
                ->whereLikeEscaped($any->qualifyColumn('description'), $term, 'or');
        });
    }

    /**
     * Past the due date and still unresolved (Open, Active or Waiting — waiting counts:
     * manual dates are absolute).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->where($query->qualifyColumn('due_at'), '<', CarbonImmutable::now())
            ->whereIn($query->qualifyColumn('status'), TicketStates::valuesIn(StatusCategory::unresolved()));
    }

    /**
     * Merged into another ticket — or, with false, not merged.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMerged(Builder $query, bool $merged = true): void
    {
        $merged
            ? $query->whereNotNull($query->qualifyColumn('merged_into_id'))
            : $query->whereNull($query->qualifyColumn('merged_into_id'));
    }

    /**
     * Whose custom field `$key` equals the value — cast to the field's type first, so a request
     * string `"42"` matches a number field's integer rows. An unknown, encrypted or multiselect
     * field throws {@see UnknownTicketFieldException}.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereField(Builder $query, string $key, mixed $value): void
    {
        $types = FieldFilters::types($key);

        $query->where(static function (Builder $any) use ($types, $key, $value): void {
            $any->whereRaw('1 = 0');

            foreach ($types as $type) {
                $cast = FieldFilters::cast($type, $value);

                if ($cast !== null) {
                    $any->orWhere(static fn (Builder $one): Builder => $one->whereAttribute($key, $cast));
                }
            }
        });
    }

    /**
     * Whose custom field `$key` lies within the bounds (inclusive); a null bound is open — a
     * one-sided range. Number, decimal, date and datetime fields only.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereFieldBetween(Builder $query, string $key, mixed $from, mixed $to): void
    {
        $types = FieldFilters::rangeTypes($key);

        $query->where(static function (Builder $any) use ($types, $key, $from, $to): void {
            $any->whereRaw('1 = 0');

            foreach ($types as $type) {
                [$low, $high] = FieldFilters::bounds($type, $from, $to);

                if ($low !== null && $high !== null) {
                    $any->orWhere(static fn (Builder $one): Builder => $one->whereAttributeBetween($key, $low, $high));
                }
            }
        });
    }

    /**
     * Order by the configured priority enum's weights (a bound `CASE`); a stored value the
     * enum no longer has weighs 0.
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrderByPriority(Builder $query, string $direction = 'desc'): void
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $cases = TicketsConfig::priorityEnum()::cases();
        $sql = 'case '.$query->getQuery()->getGrammar()->wrap($query->qualifyColumn('priority'));
        $bindings = [];

        foreach ($cases as $case) {
            $sql .= ' when ? then ?';
            $bindings[] = $case->value;
            $bindings[] = $case->weight();
        }

        // The column is grammar-wrapped and every value is bound — nothing from input.
        $query->getQuery()->addBinding($bindings, 'order');
        $query->orderBy(new RawExpression($sql.' else 0 end'), $direction);
    }

    /**
     * @param  Builder<static>  $query
     * @param  list<Model>  $models
     */
    private static function whereMorphIn(Builder $query, string $morph, array $models): void
    {
        if ($models === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(static function (Builder $any) use ($morph, $models): void {
            foreach ($models as $model) {
                $any->orWhere(static fn (Builder $one): Builder => $one
                    ->where($one->qualifyColumn($morph.'_type'), $model->getMorphClass())
                    ->where($one->qualifyColumn($morph.'_id'), $model->getKey()));
            }
        });
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
