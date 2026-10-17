<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Tickets\ChangeCategoryAction;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a category. One with live children is refused; one with live tickets needs
 * `$reassignTo` — another active category its tickets can use (not the category itself, not an
 * inactive one, not another project's: each refused with its own message) — and they move there
 * first, each exactly as `categorize()` moves it: a `category_changed` activity,
 * `TicketCategoryChanged`, and the target's required fields enforced (a ticket missing one stops
 * the whole delete). A soft delete keeps every foreign key valid.
 */
final readonly class DeleteCategoryAction
{
    public function __construct(
        private ChangeCategoryAction $changeCategory,
    ) {}

    public function execute(TicketCategory $category, TicketCategory|int|null $reassignTo = null, ?Model $actor = null): TicketCategory
    {
        $actor = ActorResolver::resolve($actor);

        return TicketTransaction::run($category, function () use ($category, $reassignTo, $actor): TicketCategory {
            AdvisoryLock::acquire(AdvisoryLock::CATEGORIES);

            if (TicketModels::category()::query()->where('parent_id', $category->getKey())->exists()) {
                throw InvalidCategoryException::hasChildren();
            }

            $tickets = TicketModels::ticket()::query()->where('category_id', $category->getKey());

            if ($tickets->exists()) {
                if ($reassignTo === null) {
                    throw InvalidCategoryException::inUse();
                }

                $target = $this->target($category, $reassignTo);

                foreach ($tickets->orderBy((new (TicketModels::ticket()))->getKeyName())->get() as $ticket) {
                    $this->changeCategory->execute($ticket, $target, $actor, system: $actor === null);
                }
            }

            $category->delete();

            return $category;
        });
    }

    private function target(TicketCategory $category, TicketCategory|int $reassignTo): TicketCategory
    {
        $target = CategoryTree::resolve($reassignTo);

        if ($target->is($category)) {
            throw InvalidCategoryException::sameTarget();
        }

        if (! $target->is_active) {
            throw InvalidCategoryException::inactive();
        }

        if ($target->project_type !== null && ($target->project_type !== $category->project_type || (string) $target->project_id !== (string) $category->project_id)) {
            throw InvalidCategoryException::projectMismatch();
        }

        return $target;
    }
}
