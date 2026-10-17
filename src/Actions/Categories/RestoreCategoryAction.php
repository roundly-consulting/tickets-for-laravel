<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Categories;

use RoundlyConsulting\Tickets\Models\TicketCategory;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Restores a soft-deleted category under the `lock:categories` advisory row (its slug was
 * kept taken while trashed, so the restore never collides).
 */
final readonly class RestoreCategoryAction
{
    public function execute(TicketCategory $category): TicketCategory
    {
        return TicketTransaction::run($category, function () use ($category): TicketCategory {
            AdvisoryLock::acquire(AdvisoryLock::CATEGORIES);

            if ($category->trashed()) {
                $category->restore();
            }

            return $category;
        });
    }
}
