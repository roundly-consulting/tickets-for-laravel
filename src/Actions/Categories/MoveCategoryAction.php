<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Moves a category under another parent (or, with null, to the root). The ancestor walk
 * runs under the `lock:categories` advisory row — locking only the two rows would let two
 * concurrent moves (A under B, B under A) each pass and close a cycle. Refused: a move under
 * itself or a descendant, a parent of another project, and a tree deeper than
 * `tickets.categories.max_depth`.
 */
final readonly class MoveCategoryAction
{
    public function execute(TicketCategory $category, TicketCategory|int|null $parent, ?int $position = null): TicketCategory
    {
        return TicketTransaction::run($category, function () use ($category, $parent, $position): TicketCategory {
            AdvisoryLock::acquire(AdvisoryLock::CATEGORIES);

            $category->refresh();
            $parent = $parent === null ? null : CategoryTree::resolve($parent)->refresh();

            if ($parent !== null) {
                foreach (CategoryTree::chain($parent) as $ancestor) {
                    if ($ancestor->is($category)) {
                        throw InvalidCategoryException::cycle();
                    }
                }

                if ($parent->project_type !== null
                    && ($parent->project_type !== $category->project_type || (string) $parent->project_id !== (string) $category->project_id)) {
                    throw InvalidCategoryException::projectMismatch();
                }

                $depth = count(CategoryTree::chain($parent)) + 1 + CategoryTree::height($category);

                if ($depth > TicketsConfig::maxCategoryDepth()) {
                    throw InvalidCategoryException::tooDeep(TicketsConfig::maxCategoryDepth());
                }
            }

            $category->forceFill([
                'parent_id' => $parent?->getKey(),
                'position' => $position ?? $category->position,
            ])->save();

            $category->unsetRelation('parent');

            return $category;
        });
    }
}
