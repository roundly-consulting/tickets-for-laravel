<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\Models\TicketTag;

/**
 * @property TicketTag $resource
 *
 * @internal
 */
final class TicketTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tag = $this->resource;

        return [
            'id' => $tag->getKey(),
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color,
            'project' => $tag->project_type === null ? null : Party::project($tag->project),
        ];
    }
}
