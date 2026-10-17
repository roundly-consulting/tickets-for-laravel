<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\TicketGuest;
use RoundlyConsulting\Tickets\Support\GuestFields;

/**
 * A guest requester. Its fields are personal data: an agent sees every configured field, the
 * guest sees their own display name, and nobody else sees anything but that it is a guest.
 *
 * @property TicketGuest $resource
 *
 * @internal
 */
final class TicketGuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $guest = $this->resource;
        $viewer = Viewer::of($request);

        if ($viewer->isAgent()) {
            return ['type' => 'guest', 'id' => $guest->getKey(), 'name' => $guest->displayName(), 'fields' => self::fields($guest)];
        }

        return ['type' => 'guest', 'name' => $viewer->is($guest) ? $guest->displayName() : null];
    }

    /**
     * Every configured guest field's value, by name — for agents only.
     *
     * @return array<string, mixed>
     */
    public static function fields(TicketGuest $guest): array
    {
        $fields = [];

        foreach (GuestFields::all() as $field) {
            $fields[$field->name] = $guest->getAttachedAttributeValue($field->name);
        }

        return $fields;
    }
}
