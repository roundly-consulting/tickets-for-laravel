<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Closure;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * Reads over the category tree, every walk bounded by `tickets.categories.max_depth` (plus
 * one, so a corrupt cycle in stored data can never loop).
 *
 * @internal
 */
final class CategoryTree
{
    /**
     * A live category by model or key.
     */
    public static function resolve(TicketCategory|int $category): TicketCategory
    {
        if ($category instanceof TicketCategory) {
            return $category;
        }

        return TicketModels::category()::query()->find($category)
            ?? throw InvalidCategoryException::notFound($category);
    }

    /**
     * The category and its ancestors, nearest first.
     *
     * @return list<TicketCategory>
     */
    public static function chain(TicketCategory $category): array
    {
        $chain = [$category];
        $seen = [$category->getKey() => true];
        $limit = TicketsConfig::maxCategoryDepth() + 1;
        $current = $category;

        while ($current->parent_id !== null && count($chain) < $limit) {
            $parent = TicketModels::category()::withTrashed()->find($current->parent_id);

            if ($parent === null || isset($seen[$parent->getKey()])) {
                break;
            }

            $seen[$parent->getKey()] = true;
            $chain[] = $parent;
            $current = $parent;
        }

        return $chain;
    }

    /**
     * The first non-null value `$pick` reads walking up from the category.
     *
     * @template TValue
     *
     * @param  Closure(TicketCategory): (TValue|null)  $pick
     * @return TValue|null
     */
    public static function nearest(TicketCategory $category, Closure $pick): mixed
    {
        foreach (self::chain($category) as $node) {
            $value = $pick($node);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The keys of every live descendant (not the category itself).
     *
     * @return list<int>
     */
    public static function descendantIds(int $id): array
    {
        $found = [];
        $level = [$id];

        for ($depth = 0; $depth < TicketsConfig::maxCategoryDepth() && $level !== []; $depth++) {
            $level = array_values(array_diff(
                array_map(intval(...), TicketModels::category()::query()->whereIn('parent_id', $level)->pluck('id')->all()),
                $found,
                [$id],
            ));

            $found = [...$found, ...$level];
        }

        return $found;
    }

    /**
     * How many levels the subtree under the category spans (0 for a leaf).
     */
    public static function height(TicketCategory $category): int
    {
        $height = 0;
        $level = [(int) $category->getKey()];

        while ($height <= TicketsConfig::maxCategoryDepth()) {
            $level = array_map(intval(...), TicketModels::category()::query()->whereIn('parent_id', $level)->pluck('id')->all());

            if ($level === []) {
                return $height;
            }

            $height++;
        }

        return $height;
    }
}
