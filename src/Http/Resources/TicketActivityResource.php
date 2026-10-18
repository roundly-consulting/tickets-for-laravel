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
 * controller reads the public timeline for them). Every `{type, id}` in the properties shows its
 * type as the alias (null outside the alias lists) — never the stored morph class: an agent keeps
 * the key, everyone else gets the alias only, no key of someone else's record.
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
            'properties' => self::properties($activity->properties ?? [], keepKeys: $agent),
            'occurred_at' => Present::date($activity->occurred_at),
        ];
    }

    /**
     * The properties with every `{type, id}` pair's type turned into its alias — the key kept for
     * an agent, dropped for everyone else.
     *
     * @param  array<array-key, mixed>  $properties
     * @return array<array-key, mixed>
     */
    private static function properties(array $properties, bool $keepKeys): array
    {
        if (count($properties) === 2 && array_key_exists('id', $properties) && is_string($properties['type'] ?? null)) {
            $alias = self::alias($properties['type']);

            return $keepKeys ? ['type' => $alias, 'id' => $properties['id']] : ['type' => $alias];
        }

        return array_map(static fn (mixed $value): mixed => is_array($value) ? self::properties($value, $keepKeys) : $value, $properties);
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
