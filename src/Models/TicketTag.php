<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Tickets\Database\Factories\TicketTagFactory;
use RoundlyConsulting\Tickets\Support\TicketModels;

/**
 * A label for tickets — global or per project, with an optional `#rrggbb` colour.
 *
 * Swappable through `tickets.models.tag`.
 *
 * @property int $id
 * @property string|null $project_type
 * @property int|string|null $project_id
 * @property string $name
 * @property string $slug
 * @property string|null $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Model|null $project
 */
class TicketTag extends Model implements Sluggable
{
    /** @use HasFactory<TicketTagFactory> */
    use HasFactory;

    use HasSlug;
    use SoftDeletes;

    protected $table = 'ticket_tags';

    protected $guarded = [];

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(
            SlugDefinition::for('slug')
                ->from('name')
                ->maxLength(80)
                ->uniqueWithin('project_type', 'project_id')
                ->regenerateOnUpdate()
                ->keepHistory(false),
        );
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(TicketModels::ticket(), 'ticket_tag', 'ticket_tag_id', 'ticket_id');
    }

    /** @return MorphTo<Model, $this> */
    public function project(): MorphTo
    {
        return $this->morphTo('project');
    }

    protected static function newFactory(): TicketTagFactory
    {
        return TicketTagFactory::new();
    }
}
