<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Http\GuestLinks;

/**
 * A file on the ticket or on one of its messages. Its `url` is short-lived: media-library's
 * temporary (signed) URL for a signed-in reader, the signed guest download for a guest — every
 * attachment is private, so no URL lasts.
 *
 * @property Media $resource
 *
 * @internal
 */
final class TicketAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $media = $this->resource;
        $guest = GuestLinks::of($request);

        return [
            'id' => $media->uuid,
            'name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'url' => $guest !== null
                ? $guest->attachment($media->uuid)
                : $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(self::LIFETIME_MINUTES)),
            'created_at' => Present::date($media->created_at),
        ];
    }

    /** How long a signed-in reader's download URL lasts. */
    public const int LIFETIME_MINUTES = 5;
}
