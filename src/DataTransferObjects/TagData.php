<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * A tag — global, or of one project — with an optional `#rrggbb` colour.
 */
final readonly class TagData
{
    public function __construct(
        public string $name,
        public ?string $color = null,
        public ?Model $project = null,
    ) {}
}
