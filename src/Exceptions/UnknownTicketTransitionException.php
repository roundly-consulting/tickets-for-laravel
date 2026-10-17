<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * The configured ticket lifecycle has no transition of that name — typically a named
 * convenience (`resolve()`, `hold()`, …) on a host lifecycle that renamed it. Maps to 422.
 */
final class UnknownTicketTransitionException extends TicketsException
{
    public static function named(string $transition): self
    {
        return new self(self::translate('lifecycle.unknown_transition', ['transition' => $transition]));
    }
}
