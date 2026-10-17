<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Actions\Internal\RecordActivityAction;
use RoundlyConsulting\Tickets\Enums\ActivityType;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\ActorResolver;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Soft-deletes a category. One with live children is refused; one with live tickets needs
 * `$reassignTo` — another active category its tickets can use — and they move there first,
 * each with a `category_changed` activity. A soft delete keeps every foreign key valid.
 */
final readonly class DeleteCategoryAction
{
    public function __construct(
        private RecordActivityAction $record,
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

                $target = CategoryTree::resolve($reassignTo);

                if ($target->is($category) || ! $target->is_active
                    || ($target->project_type !== null && ($target->project_type !== $category->project_type || (string) $target->project_id !== (string) $category->project_id))) {
                    throw InvalidCategoryException::projectMismatch();
                }

                foreach ($tickets->lockForUpdate()->get() as $ticket) {
                    $ticket->forceFill(['category_id' => $target->getKey()])->save();

                    $this->record->execute($ticket, ActivityType::CategoryChanged, $actor, [
                        'from' => (int) $category->getKey(),
                        'to' => (int) $target->getKey(),
                    ], system: true);
                }
            }

            $category->delete();

            return $category;
        });
    }
}
