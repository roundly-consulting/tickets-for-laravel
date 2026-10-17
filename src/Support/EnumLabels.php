<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Support;

use BackedEnum;

/**
 * The display label of a status or priority case — its translated `label()` when the enum has
 * one (the shipped enums do, through enums-for-laravel), else its stored value: a host's enum
 * need not.
 *
 * @internal
 */
final class EnumLabels
{
    public static function of(BackedEnum $case): string
    {
        if (method_exists($case, 'label')) {
            $label = $case->label();

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return (string) $case->value;
    }
}
