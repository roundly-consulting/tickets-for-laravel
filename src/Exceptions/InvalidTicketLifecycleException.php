<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * The configured ticket lifecycle (`tickets.lifecycle`) does not satisfy the contract the
 * package relies on: the definition, its states enum or one of its automations.
 */
final class InvalidTicketLifecycleException extends TicketsException
{
    public static function notADefinition(string $class): self
    {
        return new self(self::translate('lifecycle.not_a_definition', ['class' => $class]));
    }

    public static function broken(string $class, string $problem): self
    {
        return new self(self::translate('lifecycle.broken', ['class' => $class, 'problem' => $problem]));
    }
}
