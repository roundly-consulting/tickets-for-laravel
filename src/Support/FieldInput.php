<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;

/**
 * Validation of custom field definitions, shared by the action and the eager builder.
 *
 * @internal
 */
final class FieldInput
{
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public static function key(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidFieldException::invalidKey($key);
        }

        return $key;
    }

    /**
     * @param  array<string, string>|string  $label
     * @return array<string, string>|string
     */
    public static function label(string $key, array|string $label): array|string
    {
        $filled = is_string($label)
            ? trim($label) !== ''
            : array_filter($label, static fn (string $value): bool => trim($value) !== '') !== [];

        if (! $filled) {
            throw InvalidFieldException::emptyLabel($key);
        }

        return $label;
    }

    /**
     * The stored option list `[{value, label: {locale: text}}]` for a select type; none for
     * any other type.
     *
     * @param  array<string, array<string, string>|string>  $options
     * @return list<array{value: string, label: array<string, string>}>|null
     */
    public static function options(string $key, FieldType $type, array $options): ?array
    {
        if (! $type->hasOptions()) {
            if ($options !== []) {
                throw InvalidFieldException::unexpectedOptions($key);
            }

            return null;
        }

        if ($options === []) {
            throw InvalidFieldException::missingOptions($key);
        }

        $stored = [];

        foreach ($options as $value => $label) {
            $stored[] = [
                'value' => (string) $value,
                'label' => is_string($label) ? [app()->getLocale() => $label] : $label,
            ];
        }

        return $stored;
    }

    /**
     * Only rules from `tickets.fields.allowed_rules` — never `exists:`/`unique:` (a database
     * probe), never a closure or object.
     *
     * @param  list<string>  $rules
     * @return list<string>
     */
    public static function rules(string $key, array $rules): array
    {
        $allowed = TicketsConfig::allowedFieldRules();

        foreach ($rules as $rule) {
            $name = strtolower(explode(':', $rule, 2)[0]);

            if (! in_array($name, $allowed, true)) {
                throw InvalidFieldException::ruleNotAllowed($key, $rule);
            }
        }

        return $rules;
    }
}
