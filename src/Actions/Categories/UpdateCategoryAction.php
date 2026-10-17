<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\CategoryInput;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Changes a category's name, description, position, active flag or defaults, under the
 * `lock:categories` advisory row. Deactivating it refuses new tickets; existing ones keep it.
 */
final readonly class UpdateCategoryAction
{
    public function execute(TicketCategory $category, UpdateCategoryData $data): TicketCategory
    {
        $changes = [];

        if (Optional::given($data->name)) {
            $changes['name'] = CategoryInput::name($data->name);
        }

        if (Optional::given($data->description)) {
            $changes['description'] = $data->description;
        }

        if (Optional::given($data->position)) {
            $changes['position'] = $data->position;
        }

        if (Optional::given($data->active)) {
            $changes['is_active'] = $data->active;
        }

        if (Optional::given($data->defaultPriority)) {
            $changes['default_priority'] = CategoryInput::priority($data->defaultPriority);
        }

        if (Optional::given($data->defaultDueIn)) {
            $changes['default_due_in'] = CategoryInput::dueIn($data->defaultDueIn);
        }

        if (Optional::given($data->defaultAssignee)) {
            $changes = [...$changes, ...CategoryInput::assignee($data->defaultAssignee)];
        }

        return TicketTransaction::run($category, function () use ($category, $changes): TicketCategory {
            AdvisoryLock::acquire(AdvisoryLock::CATEGORIES);

            $category->forceFill($changes)->save();

            return $category;
        });
    }
}
