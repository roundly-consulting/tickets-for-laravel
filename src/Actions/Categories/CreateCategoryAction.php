<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use RoundlyConsulting\Tickets\DataTransferObjects\CategoryData;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryInput;
use RoundlyConsulting\Tickets\Support\CategoryTree;
use RoundlyConsulting\Tickets\Support\TicketInput;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketsConfig;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Creates a category under the `lock:categories` advisory row, which makes the slug probe
 * of global and root categories race-free (no unique index can bind their NULL scopes).
 * A child takes its parent's project unless it names its own; a project parent cannot have
 * a child of another project or a global child; the tree never exceeds
 * `tickets.categories.max_depth`.
 */
final readonly class CreateCategoryAction
{
    public function execute(CategoryData $data): TicketCategory
    {
        $name = CategoryInput::name($data->name);
        $priority = CategoryInput::priority($data->defaultPriority);
        $dueIn = CategoryInput::dueIn($data->defaultDueIn);
        $assignee = CategoryInput::assignee($data->defaultAssignee);
        $class = TicketModels::category();
        $category = new $class;

        return TicketTransaction::run($category, function () use ($category, $data, $name, $priority, $dueIn, $assignee): TicketCategory {
            AdvisoryLock::acquire(AdvisoryLock::CATEGORIES);

            [$projectType, $projectId] = TicketInput::morph($data->project);
            $parent = $data->parent === null ? null : CategoryTree::resolve($data->parent);

            if ($parent !== null) {
                if ($parent->project_type !== null && $data->project === null) {
                    [$projectType, $projectId] = [$parent->project_type, $parent->project_id];
                }

                if ($parent->project_type !== null
                    && ($parent->project_type !== $projectType || (string) $parent->project_id !== (string) $projectId)) {
                    throw InvalidCategoryException::projectMismatch();
                }

                if (count(CategoryTree::chain($parent)) + 1 > TicketsConfig::maxCategoryDepth()) {
                    throw InvalidCategoryException::tooDeep(TicketsConfig::maxCategoryDepth());
                }
            }

            $category->forceFill([
                'parent_id' => $parent?->getKey(),
                'project_type' => $projectType,
                'project_id' => $projectId,
                'name' => $name,
                'description' => $data->description,
                'position' => $data->position,
                'is_active' => $data->active,
                'default_priority' => $priority,
                'default_due_in' => $dueIn,
                ...$assignee,
            ])->save();

            return $category;
        });
    }
}
