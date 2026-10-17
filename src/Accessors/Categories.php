<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Accessors;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Tickets\Actions\Categories\CreateCategoryAction;
use RoundlyConsulting\Tickets\Actions\Categories\DeleteCategoryAction;
use RoundlyConsulting\Tickets\Actions\Categories\MoveCategoryAction;
use RoundlyConsulting\Tickets\Actions\Categories\RestoreCategoryAction;
use RoundlyConsulting\Tickets\Actions\Categories\UpdateCategoryAction;
use RoundlyConsulting\Tickets\Builders\CategoryBuilder;
use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\Enums\TicketOperation;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\TicketsManager;

/**
 * `Tickets::categories()` — the category tree.
 */
final readonly class Categories
{
    public function __construct(
        private TicketsManager $manager,
    ) {}

    /**
     * A fluent builder for a new category; a string name is the current locale's.
     *
     * @param  array<string, string>|string  $name
     */
    public function make(array|string $name): CategoryBuilder
    {
        return new CategoryBuilder($this->manager, $name);
    }

    public function create(CategoryData $data): TicketCategory
    {
        return $this->manager->perform(
            TicketOperation::CreateCategory,
            CreateCategoryAction::class,
            static fn (CreateCategoryAction $action): TicketCategory => $action->execute($data),
            ['data' => $data],
        );
    }

    public function update(TicketCategory $category, UpdateCategoryData $data): TicketCategory
    {
        return $this->manager->perform(
            TicketOperation::UpdateCategory,
            UpdateCategoryAction::class,
            static fn (UpdateCategoryAction $action): TicketCategory => $action->execute($category, $data),
            ['category' => $category, 'data' => $data],
        );
    }

    /**
     * Move a category under another parent, or to the root with null.
     */
    public function move(TicketCategory $category, TicketCategory|int|null $parent, ?int $position = null): TicketCategory
    {
        return $this->manager->perform(
            TicketOperation::MoveCategory,
            MoveCategoryAction::class,
            static fn (MoveCategoryAction $action): TicketCategory => $action->execute($category, $parent, $position),
            ['category' => $category, 'parent' => $parent instanceof TicketCategory ? $parent->getKey() : $parent],
        );
    }

    /**
     * Soft-delete a category; one with live tickets needs a category to move them to.
     */
    public function delete(TicketCategory $category, TicketCategory|int|null $reassignTo = null): TicketCategory
    {
        return $this->manager->perform(
            TicketOperation::DeleteCategory,
            DeleteCategoryAction::class,
            static fn (DeleteCategoryAction $action): TicketCategory => $action->execute($category, $reassignTo),
            ['category' => $category, 'reassign_to' => $reassignTo instanceof TicketCategory ? $reassignTo->getKey() : $reassignTo],
        );
    }

    public function restore(TicketCategory $category): TicketCategory
    {
        return $this->manager->perform(
            TicketOperation::RestoreCategory,
            RestoreCategoryAction::class,
            static fn (RestoreCategoryAction $action): TicketCategory => $action->execute($category),
            ['category' => $category],
        );
    }

    public function find(int $id): ?TicketCategory
    {
        return TicketModels::category()::query()->find($id);
    }

    /**
     * The root categories with their children loaded, level by level, ordered by position:
     * the global tree, plus the project's own categories when a project is given.
     *
     * @return Collection<int, TicketCategory>
     */
    public function tree(?Model $project = null): Collection
    {
        $scope = static function (Builder|Relation $query) use ($project): void {
            $query->where(static function (Builder $visible) use ($project): void {
                $visible->whereNull('project_type');

                if ($project !== null) {
                    $visible->orWhere(static fn (Builder $own): Builder => $own
                        ->where('project_type', $project->getMorphClass())
                        ->where('project_id', $project->getKey()));
                }
            })->orderBy('position')->orderBy('id');
        };

        $children = self::children($scope, TicketsConfig::maxCategoryDepth() - 1);

        $roots = TicketModels::category()::query()->whereNull('parent_id');
        $scope($roots);

        return $children === null ? $roots->get() : $roots->with(['children' => $children])->get();
    }

    /**
     * The constrained eager load of `$levels` more levels of children.
     *
     * @param  Closure(Builder<TicketCategory>|Relation<*, *, *>): void  $scope
     * @return (Closure(Relation<*, *, *>): void)|null
     */
    private static function children(Closure $scope, int $levels): ?Closure
    {
        if ($levels <= 0) {
            return null;
        }

        $next = self::children($scope, $levels - 1);

        return static function (Relation $query) use ($scope, $next): void {
            $scope($query);

            if ($next !== null) {
                $query->with(['children' => $next]);
            }
        };
    }
}
