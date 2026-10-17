<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A custom field definition is malformed: its key, options, rules or scope. Maps to 422.
 */
final class InvalidFieldException extends TicketsException
{
    public static function invalidKey(string $key): self
    {
        return new self(self::translate('field.invalid_key', ['key' => $key]));
    }

    public static function duplicateKey(string $key): self
    {
        return new self(self::translate('field.duplicate_key', ['key' => $key]));
    }

    public static function keptValues(string $key): self
    {
        return new self(self::translate('field.kept_values', ['key' => $key]));
    }

    public static function missingOptions(string $key): self
    {
        return new self(self::translate('field.missing_options', ['key' => $key]));
    }

    public static function unexpectedOptions(string $key): self
    {
        return new self(self::translate('field.unexpected_options', ['key' => $key]));
    }

    public static function ruleNotAllowed(string $key, string $rule): self
    {
        return new self(self::translate('field.rule_not_allowed', ['key' => $key, 'rule' => $rule]));
    }

    public static function emptyLabel(string $key): self
    {
        return new self(self::translate('field.empty_label', ['key' => $key]));
    }
}
