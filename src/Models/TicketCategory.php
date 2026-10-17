<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Tickets\Database\Factories\TicketCategoryFactory;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * A ticket category: a tree (`parent_id`), global or per project, with a translatable name
 * and the defaults a new ticket in it inherits (priority, assignee, due offset).
 *
 * Swappable through `tickets.models.category`.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string|null $project_type
 * @property int|string|null $project_id
 * @property string|null $name
 * @property string|null $description
 * @property string $slug
 * @property string|null $default_priority
 * @property string|null $default_assignee_type
 * @property int|string|null $default_assignee_id
 * @property string|null $default_due_in
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TicketCategory|null $parent
 * @property-read Collection<int, TicketCategory> $children
 * @property-read Model|null $project
 * @property-read Model|null $defaultAssignee
 */
class TicketCategory extends Model implements Sluggable, Translatable
{
    /** @use HasFactory<TicketCategoryFactory> */
    use HasFactory;

    use HasSlug;
    use HasTranslations;
    use SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $table = 'ticket_categories';

    protected $guarded = [];

    /**
     * A string slug from the name's fallback-locale value, unique among siblings of one
     * project. No history: categories are never routed by slug.
     */
    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(
            SlugDefinition::for('slug')
                ->from('name')
                ->maxLength(191)
                ->uniqueWithin('project_type', 'project_id', 'parent_id')
                ->keepHistory(false),
        );
    }

    /** @return BelongsTo<TicketCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketModels::category(), 'parent_id');
    }

    /** @return HasMany<TicketCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(TicketModels::category(), 'parent_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(TicketModels::ticket(), 'category_id');
    }

    /** @return MorphTo<Model, $this> */
    public function project(): MorphTo
    {
        return $this->morphTo('project');
    }

    /** @return MorphTo<Model, $this> */
    public function defaultAssignee(): MorphTo
    {
        return $this->morphTo('default_assignee');
    }

    protected static function newFactory(): TicketCategoryFactory
    {
        return TicketCategoryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
