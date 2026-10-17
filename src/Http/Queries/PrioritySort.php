<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\QueryBuilder\Contracts\Sort;
use RoundlyConsulting\QueryBuilder\Enums\SortDirection;

/**
 * `sort=priority` — the `orderByPriority` scope (the configured enum's weights), never the
 * stored string.
 *
 * @internal
 */
final readonly class PrioritySort implements Sort
{
    /**
     * @param  Builder<Model>  $query
     */
    public function apply(Builder $query, SortDirection $direction, string $property): void
    {
        $query->scopes(['orderByPriority' => [$direction === SortDirection::Descending ? 'desc' : 'asc']]);
    }
}
