<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\Contracts\Assignee;
use RoundlyConsulting\Tickets\Exceptions\InvalidAssigneeException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;

/**
 * Validation of category values (names, defaults) shared by create, update and the builder.
 *
 * @internal
 */
final class CategoryInput
{
    /**
     * A locale map, or a string for the current locale; never empty.
     *
     * @param  array<string, string>|string  $name
     * @return array<string, string>|string
     */
    public static function name(array|string $name): array|string
    {
        $filled = is_string($name)
            ? trim($name) !== ''
            : array_filter($name, static fn (string $value): bool => trim($value) !== '') !== [];

        if (! $filled) {
            throw InvalidCategoryException::emptyName();
        }

        return $name;
    }

    public static function dueIn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (Durations::parse($value) === null) {
            throw InvalidCategoryException::invalidDueIn($value);
        }

        return $value;
    }

    public static function priority(BackedEnum|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $case = TicketsConfig::priority($value)
            ?? throw InvalidCategoryException::invalidPriority($value instanceof BackedEnum ? (string) $value->value : $value);

        return (string) $case->value;
    }

    /**
     * @return array{default_assignee_type: string|null, default_assignee_id: int|string|null}
     */
    public static function assignee(?Model $assignee): array
    {
        if ($assignee !== null && ! $assignee instanceof Assignee) {
            throw InvalidAssigneeException::notAnAssignee($assignee);
        }

        [$type, $id] = TicketInput::morph($assignee);

        return ['default_assignee_type' => $type, 'default_assignee_id' => $id];
    }
}
