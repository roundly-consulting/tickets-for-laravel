<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * A ticket's project is not usable: it does not implement `Contracts\TicketProject`, or its
 * reference prefix is malformed. Maps to 422 — the API client reads the message, so the project
 * is named by its class basename, never its class name.
 */
final class InvalidProjectException extends TicketsException
{
    public static function notAProject(Model $project): self
    {
        return new self(self::translate('project.not_a_project', ['class' => class_basename($project)]));
    }

    public static function invalidPrefix(string $prefix): self
    {
        return new self(self::translate('project.invalid_prefix', ['prefix' => $prefix]));
    }
}
