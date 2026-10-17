<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Tickets\Exceptions\InvalidCategoryException;
use RoundlyConsulting\Tickets\Models\TicketCategory;

/**
 * Validation of the ticket values every write path shares (create, update, the API).
 *
 * @internal
 */
final class TicketInput
{
    public static function subject(string $subject): string
    {
        $subject = trim($subject);

        if ($subject === '' || mb_strlen($subject) > 255) {
            throw ValidationException::withMessages(['subject' => [Messages::get('ticket.invalid_subject')]]);
        }

        return $subject;
    }

    public static function channel(?string $channel): string
    {
        $channel ??= TicketsConfig::defaultChannel();

        if (! in_array($channel, TicketsConfig::channels(), true)) {
            throw ValidationException::withMessages(['channel' => [Messages::get('ticket.unknown_channel', ['channel' => $channel])]]);
        }

        return $channel;
    }

    /**
     * A live, active category the ticket's project may use.
     */
    public static function category(TicketCategory|int $category, ?string $projectType, int|string|null $projectId): TicketCategory
    {
        $category = CategoryTree::resolve($category);

        if ($category->trashed() || ! $category->is_active) {
            throw InvalidCategoryException::inactive();
        }

        if ($category->project_type !== null
            && ($category->project_type !== $projectType || (string) $category->project_id !== (string) $projectId)) {
            throw InvalidCategoryException::projectMismatch();
        }

        return $category;
    }

    /**
     * The stored value of a priority of the configured enum.
     */
    public static function priority(BackedEnum|string $priority): string
    {
        $case = TicketsConfig::priority($priority)
            ?? throw ValidationException::withMessages(['priority' => [Messages::get('ticket.unknown_priority')]]);

        return (string) $case->value;
    }

    /**
     * The priority a new ticket gets when the caller gives none: the nearest category
     * default, else `tickets.priority.default`.
     */
    public static function defaultPriority(?TicketCategory $category): string
    {
        $inherited = $category === null ? null : CategoryTree::nearest(
            $category,
            static fn (TicketCategory $node): ?string => $node->default_priority === null
                ? null
                : self::stored(TicketsConfig::priority($node->default_priority)),
        );

        return $inherited ?? (string) TicketsConfig::defaultPriority()->value;
    }

    /**
     * The project morph of a model, as `[type, id]`.
     *
     * @return array{0: string|null, 1: int|string|null}
     */
    public static function morph(?Model $model): array
    {
        $key = $model?->getKey();

        return [$model?->getMorphClass(), is_int($key) || is_string($key) ? $key : null];
    }

    private static function stored(?BackedEnum $case): ?string
    {
        return $case === null ? null : (string) $case->value;
    }
}
