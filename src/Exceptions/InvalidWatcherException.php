<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * `watch()` / `unwatch()` act for the handle's actor — a call without one (as the system, or
 * with nobody signed in) has no one to watch for. Maps to 422.
 */
final class InvalidWatcherException extends TicketsException
{
    public static function noActor(): self
    {
        return new self(self::translate('watcher.no_actor'));
    }
}
