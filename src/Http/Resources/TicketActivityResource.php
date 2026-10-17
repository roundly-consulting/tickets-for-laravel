<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\TicketActivity;
use RoundlyConsulting\Tickets\Support\MorphAliases;

/**
 * One entry of the activity timeline. Nobody but an agent is handed an internal entry (the
 * controller reads the public timeline for them), and for them every `{type, id}` in the
 * properties shows as an alias only — no class name, no key of someone else's record.
 *
 * @property TicketActivity $resource
 *
 * @internal
 */
final class TicketActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $viewer = Viewer::of($request);
        $agent = $viewer->isAgentOn($activity->ticket);

        return [
            'id' => $activity->getKey(),
            'type' => $activity->type->value,
            'label' => $activity->type->label(),
            'system' => $activity->is_system,
            'actor' => Party::person($activity->actor_type === null ? null : $activity->actor, $viewer, $agent),
            ...($agent ? ['visibility' => $activity->visibility->value] : []),
            'properties' => $agent ? ($activity->properties ?? []) : self::public($activity->properties ?? []),
            'occurred_at' => Present::date($activity->occurred_at),
        ];
    }

    /**
     * The properties with every `{type, id}` pair reduced to the type's alias.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private static function public(array $properties): array
    {
        if (isset($properties['type'], $properties['id']) && is_string($properties['type']) && count($properties) === 2) {
            return ['type' => self::alias($properties['type'])];
        }

        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::public($value) : $value, $properties);
    }

    private static function alias(string $morph): ?string
    {
        foreach ([MorphAliases::REQUESTERS, MorphAliases::ASSIGNABLES, MorphAliases::WATCHABLES] as $kind) {
            foreach (MorphAliases::map($kind) as $alias => $class) {
                if ((new $class)->getMorphClass() === $morph) {
                    return $alias;
                }
            }
        }

        return null;
    }
}
