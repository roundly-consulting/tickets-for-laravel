<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Attributes\Models\Attribute;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestData;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestField;
use RoundlyConsulting\Tickets\Models\TicketGuest;

/**
 * Validation and identity lookups for guest fields. Lookups read the attribute rows
 * directly — a locking read when re-reading after a lost race, so MySQL REPEATABLE READ
 * cannot hide the winner's committed row.
 *
 * @internal
 */
final class GuestDirectory
{
    /**
     * The submitted fields, normalised and validated against their configured rules.
     * Unknown keys are refused; unless `$partial`, every configured field is validated
     * and (with `require_one_identifying`) at least one identifying field must be given.
     *
     * @param  array<string, GuestField>  $fields
     * @return array<string, mixed>
     */
    public static function validated(GuestData $data, array $fields, bool $partial): array
    {
        $values = [];
        $errors = [];

        foreach ($data->fields as $name => $value) {
            if (! isset($fields[$name])) {
                $errors['fields.'.$name][] = Messages::get('guest.unknown_field', ['field' => (string) $name]);

                continue;
            }

            $values[$name] = $fields[$name]->normalise($value);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $rules = [];

        foreach ($fields as $name => $field) {
            if (! $partial || array_key_exists($name, $values)) {
                $rules['fields.'.$name] = $field->rules;
            }
        }

        Validator::make(['fields' => $values], $rules)->validate();

        if (! $partial && GuestFields::requireOneIdentifying()) {
            $given = array_filter(
                $fields,
                static fn (GuestField $field): bool => $field->identifying && ($values[$field->name] ?? null) !== null,
            );

            $first = array_values(array_filter($fields, static fn (GuestField $field): bool => $field->identifying))[0] ?? null;

            if ($given === [] && $first !== null) {
                throw ValidationException::withMessages([
                    'fields.'.$first->name => [Messages::get('guest.identifying_required')],
                ]);
            }
        }

        return $values;
    }

    /**
     * The guest the first matching identifying field (config order) belongs to.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, GuestField>  $fields
     */
    public static function match(array $values, array $fields, bool $locking): ?TicketGuest
    {
        foreach ($fields as $name => $field) {
            $value = $values[$name] ?? null;

            if (! $field->identifying || $value === null) {
                continue;
            }

            $owner = self::ownerOf($field, $value, $locking);

            if ($owner !== null) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Whether the matched guest holds a different value of an identifying field the
     * submission also gives.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, GuestField>  $fields
     */
    public static function conflicts(TicketGuest $guest, array $values, array $fields): bool
    {
        foreach ($fields as $name => $field) {
            $value = $values[$name] ?? null;

            if (! $field->identifying || $value === null) {
                continue;
            }

            $current = $guest->getAttachedAttributeValue($name);

            if ($current !== null && $current !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The submission without the identifying values another guest already holds.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, GuestField>  $fields
     * @return array<string, mixed>
     */
    public static function unclaimed(array $values, array $fields): array
    {
        return array_filter(
            $values,
            static fn (mixed $value, string $name): bool => $value === null
                || ! $fields[$name]->identifying
                || self::ownerOf($fields[$name], $value, locking: false) === null,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    public static function heldByAnother(GuestField $field, mixed $value, TicketGuest $guest): bool
    {
        $owner = self::ownerOf($field, $value, locking: false);

        return $owner !== null && ! $owner->is($guest);
    }

    /**
     * The guest (trashed included — a soft-deleted guest still holds its values) whose
     * live attribute row stores this identifying value.
     */
    private static function ownerOf(GuestField $field, mixed $value, bool $locking): ?TicketGuest
    {
        $class = TicketModels::guest();
        $guest = new $class;

        /** @var Builder<Attribute> $rows */
        $rows = $guest->attachedAttributes()->getRelated()->newQuery()
            ->where('owner_type', $guest->getMorphClass())
            ->where('name', $field->name)
            ->where('is_encrypted', false)
            ->where('value', $field->type->toStorage($value));

        $owner = ($locking ? $rows->lockForUpdate() : $rows)->value('owner_id');

        if ($owner === null) {
            return null;
        }

        // The guest row is read the same way: under MySQL REPEATABLE READ a plain read
        // answers from the snapshot taken before the winner committed and misses it.
        $guests = $class::withTrashed()->whereKey($owner);

        return ($locking ? $guests->lockForUpdate() : $guests)->first();
    }
}
