<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Tickets\Database\Factories\TicketFieldFactory;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Translatable\Concerns\HasTranslations;
use RoundlyConsulting\Translatable\Contracts\Translatable;

/**
 * A custom field definition — global, per project, or per category (and its descendants).
 * Values live in attributes-for-laravel on the ticket.
 *
 * Swappable through `tickets.models.field`.
 *
 * @property int $id
 * @property string|null $project_type
 * @property int|string|null $project_id
 * @property int|null $category_id
 * @property string $key
 * @property string|null $label
 * @property string|null $help
 * @property FieldType $type
 * @property list<array<string, mixed>>|null $options
 * @property list<string>|null $rules
 * @property bool $required
 * @property bool $encrypted
 * @property mixed $default
 * @property bool $requester_visible
 * @property bool $requester_editable
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read TicketCategory|null $category
 * @property-read Model|null $project
 */
class TicketField extends Model implements Translatable
{
    /** @use HasFactory<TicketFieldFactory> */
    use HasFactory;

    use HasTranslations;
    use SoftDeletes;

    /** @var list<string> */
    public array $translatable = ['label', 'help'];

    protected $table = 'ticket_fields';

    protected $guarded = [];

    /** @return BelongsTo<TicketCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketModels::category(), 'category_id');
    }

    /** @return MorphTo<Model, $this> */
    public function project(): MorphTo
    {
        return $this->morphTo('project');
    }

    protected static function newFactory(): TicketFieldFactory
    {
        return TicketFieldFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FieldType::class,
            'options' => 'array',
            'rules' => 'array',
            'default' => 'json',
            'required' => 'boolean',
            'encrypted' => 'boolean',
            'requester_visible' => 'boolean',
            'requester_editable' => 'boolean',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
