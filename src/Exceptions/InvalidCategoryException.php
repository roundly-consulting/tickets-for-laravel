<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Exceptions;

/**
 * A category write or assignment breaks a tree rule. Maps to 422.
 */
final class InvalidCategoryException extends TicketsException
{
    public static function cycle(): self
    {
        return new self(self::translate('category.cycle'));
    }

    public static function tooDeep(int $max): self
    {
        return new self(self::translate('category.too_deep', ['max' => $max]));
    }

    public static function projectMismatch(): self
    {
        return new self(self::translate('category.project_mismatch'));
    }

    public static function inactive(): self
    {
        return new self(self::translate('category.inactive'));
    }

    public static function inUse(): self
    {
        return new self(self::translate('category.in_use'));
    }

    public static function hasChildren(): self
    {
        return new self(self::translate('category.has_children'));
    }

    public static function notFound(int|string $key): self
    {
        return new self(self::translate('category.not_found', ['key' => (string) $key]));
    }

    public static function invalidDueIn(string $value): self
    {
        return new self(self::translate('category.invalid_due_in', ['value' => $value]));
    }

    public static function invalidPriority(string $value): self
    {
        return new self(self::translate('category.invalid_priority', ['value' => $value]));
    }

    public static function emptyName(): self
    {
        return new self(self::translate('category.empty_name'));
    }
}
