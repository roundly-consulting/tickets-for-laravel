<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use RoundlyConsulting\Attributes\Enums\AttributeType;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Tickets\DataTransferObjects\GuestField;
use RoundlyConsulting\Tickets\Exceptions\InvalidTicketsConfigurationException;

/**
 * The guest fields declared in `tickets.guests.fields`, validated on every read: names
 * match `^[a-z][a-z0-9_]{0,63}$`, types are attribute types, exactly one field is the
 * display field, and an identifying field is never encrypted (equality lookups compare the
 * stored text, which for an encrypted value is a non-deterministic ciphertext).
 *
 * @internal
 */
final class GuestFields
{
    /**
     * @return array<string, GuestField>
     */
    public static function all(): array
    {
        $configured = config('tickets.guests.fields');

        if (! is_array($configured) || $configured === []) {
            throw InvalidTicketsConfigurationException::invalid('tickets.guests.fields', 'a non-empty map of field name => definition');
        }

        $fields = [];

        foreach ($configured as $name => $spec) {
            $fields[(string) $name] = self::field((string) $name, $spec);
        }

        $display = array_filter($fields, static fn (GuestField $field): bool => $field->display);

        if (count($display) !== 1) {
            throw InvalidTicketsConfigurationException::invalid('tickets.guests.fields', 'a map with exactly one `display` field');
        }

        return $fields;
    }

    /**
     * The identifying fields, in config order — the order resolution tries them in.
     *
     * @return list<GuestField>
     */
    public static function identifying(): array
    {
        return array_values(array_filter(self::all(), static fn (GuestField $field): bool => $field->identifying));
    }

    public static function display(): GuestField
    {
        foreach (self::all() as $field) {
            if ($field->display) {
                return $field;
            }
        }

        // all() guarantees exactly one display field.
        throw InvalidTicketsConfigurationException::invalid('tickets.guests.fields', 'a map with exactly one `display` field');
    }

    public static function requireOneIdentifying(): bool
    {
        return Config::using(InvalidTicketsConfigurationException::class)->boolean('tickets.guests.require_one_identifying', true);
    }

    public static function updateOnResolve(): bool
    {
        return Config::using(InvalidTicketsConfigurationException::class)->boolean('tickets.guests.update_on_resolve', false);
    }

    /**
     * The raw attribute definitions the guest model declares — attributes-for-laravel only
     * accepts arrays. Identifying fields are unique among all guests.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function attributeDefinitions(): array
    {
        $definitions = [];

        foreach (self::all() as $field) {
            $definitions[$field->name] = [
                'type' => $field->type->value,
                'rules' => $field->rules,
                'unique' => $field->identifying ? 'owner' : 'none',
                'encrypted' => $field->encrypted,
            ];
        }

        return $definitions;
    }

    private static function field(string $name, mixed $spec): GuestField
    {
        $key = 'tickets.guests.fields.'.$name;

        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) !== 1 || ! is_array($spec)) {
            throw InvalidTicketsConfigurationException::invalid($key, 'a definition array under a snake_case name');
        }

        $type = AttributeType::tryFrom(is_string($spec['type'] ?? null) ? $spec['type'] : 'string');
        $rules = $spec['rules'] ?? [];
        $route = $spec['route'] ?? null;
        $normalize = $spec['normalize'] ?? null;
        $read = Config::for([
            $key.'.display' => $spec['display'] ?? null,
            $key.'.identifying' => $spec['identifying'] ?? null,
            $key.'.encrypted' => $spec['encrypted'] ?? null,
        ], InvalidTicketsConfigurationException::class);

        if ($type === null) {
            throw InvalidTicketsConfigurationException::invalid($key.'.type', 'one of '.implode(', ', AttributeType::values()->all()));
        }

        if (! is_array($rules) || ! array_is_list($rules)) {
            throw InvalidTicketsConfigurationException::invalid($key.'.rules', 'a list of validation rules');
        }

        if ($route !== null && ! is_string($route)) {
            throw InvalidTicketsConfigurationException::invalid($key.'.route', 'a notification channel name');
        }

        if ($normalize !== null && ! in_array($normalize, ['email', 'phone'], true)) {
            throw InvalidTicketsConfigurationException::invalid($key.'.normalize', 'email, phone or null');
        }

        $identifying = $read->boolean($key.'.identifying');
        $encrypted = $read->boolean($key.'.encrypted');

        if ($identifying && $encrypted) {
            throw InvalidTicketsConfigurationException::invalid($key, 'not both identifying and encrypted');
        }

        return new GuestField(
            name: $name,
            type: $type,
            rules: $rules,
            display: $read->boolean($key.'.display'),
            identifying: $identifying,
            route: $route,
            encrypted: $encrypted,
            normalize: $normalize,
        );
    }
}
