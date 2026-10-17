<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use RoundlyConsulting\Tickets\Models\Ticket;

/**
 * The tickets cannot be merged: the same ticket, a Closed or already-merged source or target
 * (the error names where a merged target went), a cross-project merge with
 * `tickets.merge.cross_project` off, or an actor without the merge ability. Maps to 422.
 */
final class InvalidMergeException extends TicketsException
{
    public static function same(): self
    {
        return new self(self::translate('merge.same'));
    }

    public static function sourceClosed(Ticket $source): self
    {
        return new self(self::translate('merge.source_closed', ['reference' => $source->reference]));
    }

    public static function sourceMerged(Ticket $source): self
    {
        return new self(self::translate('merge.source_merged', ['reference' => $source->reference]));
    }

    public static function targetClosed(Ticket $target): self
    {
        return new self(self::translate('merge.target_closed', ['reference' => $target->reference]));
    }

    public static function targetMerged(Ticket $target, string $final): self
    {
        return new self(self::translate('merge.target_merged', ['reference' => $target->reference, 'final' => $final]));
    }

    public static function crossProject(): self
    {
        return new self(self::translate('merge.cross_project'));
    }

    public static function unauthorized(): self
    {
        return new self(self::translate('merge.unauthorized'));
    }
}
