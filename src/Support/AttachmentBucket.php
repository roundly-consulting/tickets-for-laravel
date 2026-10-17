<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Exceptions\TicketAttachmentNotFoundException;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The ticket's own attachments bucket, read and scoped.
 *
 * @internal
 */
final class AttachmentBucket
{
    /**
     * @return Collection<int, Media>
     */
    public static function all(Ticket $ticket): Collection
    {
        return $ticket->getMedia(TicketsConfig::attachmentsBucket());
    }

    /**
     * The media, once it is known to be one of the ticket's attachments — by model or uuid.
     */
    public static function owned(Ticket $ticket, Media|string $media): Media
    {
        $uuid = $media instanceof Media ? $media->uuid : $media;

        // Postgres types the column as uuid and refuses to compare anything else with it.
        if (! Str::isUuid($uuid)) {
            throw TicketAttachmentNotFoundException::on($ticket, $media);
        }

        $found = $ticket->media()->where('uuid', $uuid)->where('bucket_name', TicketsConfig::attachmentsBucket())->first();

        return $found ?? throw TicketAttachmentNotFoundException::on($ticket, $media);
    }
}
