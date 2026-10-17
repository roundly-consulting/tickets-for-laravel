<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * The activity timeline is append-only: an entry is never updated or deleted on its own.
 */
final class ActivityIsAppendOnlyException extends TicketsException
{
    public static function make(): self
    {
        return new self(self::translate('activity.append_only'));
    }
}
