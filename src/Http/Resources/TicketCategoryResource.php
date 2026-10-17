<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * A category with its loaded children, recursively — the tree. Agents also see every translation,
 * the defaults a new ticket takes from it and the inactive children (with their subtrees), which
 * anyone else can't file into and never sees.
 *
 * @property TicketCategory $resource
 *
 * @internal
 */
final class TicketCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;
        $agent = Viewer::of($request)->isAgent();

        $data = [
            'id' => $category->getKey(),
            'name' => $category->name,
            'description' => $category->description,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'project' => $category->project_type === null ? null : Party::project($category->project),
            'position' => $category->position,
            'is_active' => $category->is_active,
        ];

        if ($agent) {
            $data['translations'] = [
                'name' => $category->getTranslations('name'),
                'description' => $category->getTranslations('description'),
            ];
            $data['defaults'] = [
                'priority' => $category->default_priority,
                'assignee' => $category->default_assignee_type === null ? null : Party::person($category->defaultAssignee, Viewer::of($request), true),
                'due_in' => $category->default_due_in,
            ];
        }

        if ($category->relationLoaded('children')) {
            $data['children'] = $category->children
                ->filter(static fn (TicketCategory $child): bool => $agent || $child->is_active)
                ->map(static fn (TicketCategory $child): array => (new self($child))->toArray($request))
                ->values()
                ->all();
        }

        return $data;
    }
}
