<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Attributes\Enums\AttributeType;
use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;

/**
 * The type of a custom ticket field, stored through attributes-for-laravel.
 */
#[TranslatedLabels('tickets::enums.field_type')]
enum FieldType: string implements HasColor
{
    use Helpers;

    case Text = 'text';
    case Textarea = 'textarea';
    case Email = 'email';
    case Url = 'url';
    case Number = 'number';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Select = 'select';
    case Multiselect = 'multiselect';

    /** The attributes-for-laravel type the value is stored as. */
    public function attributeType(): AttributeType
    {
        return match ($this) {
            self::Text, self::Textarea, self::Email, self::Url, self::Select => AttributeType::String_,
            self::Number => AttributeType::Integer,
            self::Decimal => AttributeType::Float_,
            self::Boolean => AttributeType::Boolean,
            self::Date, self::DateTime => AttributeType::DateTime,
            self::Multiselect => AttributeType::Array_,
        };
    }

    /** Whether the type carries an option list (select, multiselect). */
    public function hasOptions(): bool
    {
        return $this === self::Select || $this === self::Multiselect;
    }

    public function color(): string
    {
        return match ($this) {
            self::Text, self::Textarea => 'gray',
            self::Email, self::Url => 'sky',
            self::Number, self::Decimal => 'indigo',
            self::Boolean => 'green',
            self::Date, self::DateTime => 'amber',
            self::Select, self::Multiselect => 'purple',
        };
    }
}
