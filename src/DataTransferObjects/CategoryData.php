<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * A new category. The name and description are locale maps (`['en' => …, 'sk' => …]`) or a
 * string for the current locale. A child inherits its parent's project when none is given.
 */
final readonly class CategoryData
{
    /**
     * @param  array<string, string>|string  $name
     * @param  array<string, string>|string|null  $description
     * @param  string|null  $defaultDueIn  an ISO-8601 duration (`P2D`, `PT4H`)
     */
    public function __construct(
        public array|string $name,
        public array|string|null $description = null,
        public TicketCategory|int|null $parent = null,
        public ?Model $project = null,
        public int $position = 0,
        public bool $active = true,
        public BackedEnum|string|null $defaultPriority = null,
        public ?Model $defaultAssignee = null,
        public ?string $defaultDueIn = null,
    ) {}
}
