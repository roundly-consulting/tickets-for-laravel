<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Comments\Models\Comment;
use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The comment is not part of this ticket's conversation — a handle never reaches into another
 * ticket's thread. Maps to 404.
 */
final class TicketCommentNotFoundException extends TicketsException
{
    public static function on(Ticket $ticket, Comment $comment): self
    {
        return self::missing($ticket, $comment->getKey());
    }

    public static function missing(Ticket $ticket, mixed $comment): self
    {
        return new self(self::translate('conversation.comment_not_found', [
            'comment' => is_scalar($comment) ? (string) $comment : '',
            'reference' => $ticket->reference,
        ]));
    }
}
