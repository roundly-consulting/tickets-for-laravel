<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\UnknownTicketFieldException;
use RoundlyConsulting\Tickets\Models\TicketField;
use Throwable;

/**
 * The typed side of the custom field query scopes. Attributes resolves a filter's type on the
 * query's blank ticket — no category, so a category field is unknown there — and a request
 * string would be compared as a string, never matching an integer row. So the value is cast to
 * the field's own type first; a key defined with different types in different projects is
 * matched in each.
 *
 * @internal
 */
final class FieldFilters
{
    /**
     * The types a filterable key is defined with across the live, active fields.
     *
     * @return list<FieldType>
     */
    public static function types(string $key): array
    {
        $fields = TicketModels::field()::query()->where('key', $key)->where('is_active', true)->get();

        if ($fields->isEmpty()) {
            throw UnknownTicketFieldException::unknown($key);
        }

        if ($fields->contains(static fn (TicketField $field): bool => $field->encrypted)) {
            throw UnknownTicketFieldException::encrypted($key);
        }

        $types = array_values(array_unique(
            array_map(static fn (TicketField $field): FieldType => $field->type, $fields->all()),
            SORT_REGULAR,
        ));

        if (in_array(FieldType::Multiselect, $types, true)) {
            throw UnknownTicketFieldException::notFilterable($key);
        }

        return $types;
    }

    /**
     * The types of a key a range filter works on: number, decimal, date and datetime.
     *
     * @return list<FieldType>
     */
    public static function rangeTypes(string $key): array
    {
        $types = self::types($key);

        foreach ($types as $type) {
            if (! in_array($type, [FieldType::Number, FieldType::Decimal, FieldType::Date, FieldType::DateTime], true)) {
                throw UnknownTicketFieldException::notFilterable($key);
            }
        }

        return $types;
    }

    /**
     * The value as the type stores it, or null when it is no value of that type.
     */
    public static function cast(FieldType $type, mixed $value): int|float|bool|string|CarbonImmutable|null
    {
        return match ($type) {
            FieldType::Number => is_int($value) ? $value : (is_string($value) && ($int = filter_var($value, FILTER_VALIDATE_INT)) !== false ? $int : null),
            FieldType::Decimal => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : null,
            FieldType::Boolean => is_bool($value) ? $value : (is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null),
            FieldType::Date, FieldType::DateTime => self::date($value),
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * Both bounds of a range, cast; an open (null) bound is padded with the type's extreme —
     * attributes' range scope needs both.
     *
     * @return array{0: int|float|CarbonImmutable|null, 1: int|float|CarbonImmutable|null}
     */
    public static function bounds(FieldType $type, mixed $from, mixed $to): array
    {
        $cast = static function (mixed $bound) use ($type): int|float|CarbonImmutable|null {
            $value = self::cast($type, $bound);

            return is_bool($value) || is_string($value) ? null : $value;
        };

        return match ($type) {
            FieldType::Number => [$from === null ? PHP_INT_MIN : $cast($from), $to === null ? PHP_INT_MAX : $cast($to)],
            FieldType::Decimal => [$from === null ? -1.0e15 : $cast($from), $to === null ? 1.0e15 : $cast($to)],
            default => [
                $from === null ? CarbonImmutable::parse('0001-01-01 00:00:00', 'UTC') : $cast($from),
                $to === null ? CarbonImmutable::parse('9999-12-31 23:59:59', 'UTC') : $cast($to),
            ],
        };
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
