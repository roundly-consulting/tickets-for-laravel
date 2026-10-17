<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The media is not one of this ticket's attachments — a handle never reaches another owner's
 * files. Maps to 404.
 */
final class TicketAttachmentNotFoundException extends TicketsException
{
    public static function on(Ticket $ticket, Media|string $media): self
    {
        return new self(self::translate('attachment.not_found', [
            'media' => $media instanceof Media ? $media->uuid : $media,
            'reference' => $ticket->reference,
        ]));
    }
}
