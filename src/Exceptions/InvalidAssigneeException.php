<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * The model cannot be assigned the ticket. Maps to 422.
 */
final class InvalidAssigneeException extends TicketsException
{
    public static function notAnAssignee(Model $model): self
    {
        return new self(self::translate('assignee.not_an_assignee', ['class' => $model::class]));
    }

    public static function noActor(): self
    {
        return new self(self::translate('assignee.no_actor'));
    }

    public static function ineligible(Model $model): self
    {
        return new self(self::translate('assignee.ineligible', ['class' => $model::class]));
    }
}
