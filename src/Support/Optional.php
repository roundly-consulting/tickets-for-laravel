<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

/**
 * "Not given" in an update DTO, so that `null` can mean "clear the value":
 * `new UpdateTicketData(dueAt: null)` clears the due date, `new UpdateTicketData()` leaves
 * it alone.
 */
final readonly class Optional
{
    /**
     * Whether a DTO property was given (anything but the sentinel).
     *
     * @phpstan-assert-if-true !Optional $value
     */
    public static function given(mixed $value): bool
    {
        return ! $value instanceof self;
    }
}
