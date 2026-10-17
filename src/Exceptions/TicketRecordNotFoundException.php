<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * No category, field, tag or ticket link has the id — or, for a link, none of that id joins this
 * ticket to another (the API never reaches another ticket's links). Maps to 404;
 * {@see self::errorCode()} names what was missing.
 */
final class TicketRecordNotFoundException extends TicketsException
{
    private string $record = 'record';

    public static function link(Ticket $ticket, int|string $link): self
    {
        return self::of('link', self::translate('link.not_found', ['link' => (string) $link, 'reference' => $ticket->reference]));
    }

    public static function category(int|string $id): self
    {
        return self::of('category', self::translate('category.not_found', ['key' => (string) $id]));
    }

    public static function field(int|string $id): self
    {
        return self::of('field', self::translate('field.not_found', ['id' => (string) $id]));
    }

    public static function tag(int|string $id): self
    {
        return self::of('tag', self::translate('tag.not_found', ['id' => (string) $id]));
    }

    /**
     * `link_not_found`, `category_not_found`, `field_not_found` or `tag_not_found`.
     */
    public function errorCode(): string
    {
        return $this->record.'_not_found';
    }

    private static function of(string $record, string $message): self
    {
        $exception = new self($message);
        $exception->record = $record;

        return $exception;
    }
}
