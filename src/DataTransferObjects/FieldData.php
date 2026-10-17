<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * A custom field definition — global, per project, or per category (and its descendants).
 */
final readonly class FieldData
{
    /**
     * @param  array<string, string>|string  $label
     * @param  array<string, string>|string|null  $help
     * @param  array<string, array<string, string>|string>  $options  option value => label (a locale map or a string)
     * @param  list<string>  $rules  extra validation rules, from `tickets.fields.allowed_rules`
     */
    public function __construct(
        public string $key,
        public FieldType $type,
        public array|string $label,
        public array|string|null $help = null,
        public array $options = [],
        public array $rules = [],
        public bool $required = false,
        public bool $encrypted = false,
        public mixed $default = null,
        public bool $requesterVisible = true,
        public bool $requesterEditable = false,
        public int $position = 0,
        public bool $active = true,
        public ?Model $project = null,
        public TicketCategory|int|null $category = null,
    ) {}
}
