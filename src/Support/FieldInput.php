<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\InvalidFieldException;
use RoundlyConsulting\Tickets\Models\TicketField;

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

    /** The longest `regex:` pattern a field rule may carry. */
    public const int MAX_PATTERN_LENGTH = 255;

    /** Rules that probe the database — refused even if a host allow-lists them. */
    private const array DATABASE_RULES = ['exists', 'unique'];

    /**
     * Only rule strings from `tickets.fields.allowed_rules` — never `exists:` / `unique:` (a
     * database probe, whatever the allow-list says), never a closure or object. A `regex:`
     * pattern is length-capped and must compile, so a definition can never carry a broken or
     * runaway pattern into every later validation.
     *
     * @param  list<mixed>  $rules
     * @return list<string>
     */
    public static function rules(string $key, array $rules): array
    {
        $allowed = TicketsConfig::allowedFieldRules();
        $checked = [];

        foreach ($rules as $rule) {
            if (! is_string($rule)) {
                throw InvalidFieldException::ruleNotAllowed($key, get_debug_type($rule));
            }

            [$name, $parameters] = array_pad(explode(':', $rule, 2), 2, null);
            $name = strtolower($name);

            if (! in_array($name, $allowed, true) || in_array($name, self::DATABASE_RULES, true)) {
                throw InvalidFieldException::ruleNotAllowed($key, $rule);
            }

            if ($name === 'regex' && ! self::safePattern((string) $parameters)) {
                throw InvalidFieldException::ruleNotAllowed($key, $rule);
            }

            $checked[] = $rule;
        }

        return $checked;
    }

    /**
     * A key no live field of the same scope holds: a project field collides with a global one
     * and with its own project's fields; a global field with every field of the key, in any
     * project. Run it under the `lock:fields` advisory row.
     */
    public static function assertKeyFree(string $key, ?string $projectType, int|string|null $projectId, ?int $except = null): void
    {
        $taken = self::sameScope(TicketModels::field()::query()->where('key', $key), $projectType, $projectId);

        if ($except !== null) {
            $taken->whereKeyNot($except);
        }

        if ($taken->exists()) {
            throw InvalidFieldException::duplicateKey($key);
        }
    }

    /**
     * A key no deleted field of the same scope still keeps values under, on the tickets a new
     * field of that scope would apply to. Those values are hidden, not gone: a new field would
     * show them — to whoever it is visible to, decrypted — so the old field is restored or its
     * values are pruned first. Run it under the `lock:fields` advisory row.
     */
    public static function assertNoKeptValues(string $key, ?string $projectType, int|string|null $projectId): void
    {
        if (self::sameScope(TicketModels::field()::onlyTrashed()->where('key', $key), $projectType, $projectId)->doesntExist()) {
            return;
        }

        $kept = TicketModels::ticket()::withTrashed()
            ->whereHas('attachedAttributes', static fn (Builder $values): Builder => $values->where('name', $key));

        if ($projectType !== null) {
            $kept->where('project_type', $projectType)->where('project_id', $projectId);
        }

        if ($kept->exists()) {
            throw InvalidFieldException::keptValues($key);
        }
    }

    /**
     * The fields of a key that share a scope with a (new) field: for a project field, the
     * global ones and its project's; for a global field, every one.
     *
     * @template TModel of TicketField
     *
     * @param  Builder<TModel>  $fields
     * @return Builder<TModel>
     */
    private static function sameScope(Builder $fields, ?string $projectType, int|string|null $projectId): Builder
    {
        if ($projectType !== null) {
            $fields->where(static fn (Builder $scope): Builder => $scope
                ->whereNull('project_type')
                ->orWhere(static fn (Builder $same): Builder => $same->where('project_type', $projectType)->where('project_id', $projectId)));
        }

        return $fields;
    }

    private static function safePattern(string $pattern): bool
    {
        return $pattern !== ''
            && mb_strlen($pattern) <= self::MAX_PATTERN_LENGTH
            && preg_match('/^([\/#~%]).*\1[imsxuADU]*$/s', $pattern) === 1
            && @preg_match($pattern, '') !== false;
    }
}
