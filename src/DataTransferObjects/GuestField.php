<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Attributes\Enums\AttributeType;

/**
 * One guest field declared in `tickets.guests.fields`, validated.
 */
final readonly class GuestField
{
    /**
     * @param  list<mixed>  $rules
     */
    public function __construct(
        public string $name,
        public AttributeType $type,
        public array $rules,
        public bool $display,
        public bool $identifying,
        public ?string $route,
        public bool $encrypted,
        public ?string $normalize,
    ) {}

    /**
     * The value as it is stored and matched: trimmed; e-mail addresses lower-cased; phone
     * numbers without spaces, dashes, dots or brackets.
     */
    public function normalise(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($this->normalize) {
            'email' => mb_strtolower($value),
            'phone' => (string) preg_replace('/[\s\-.()]/', '', $value),
            default => $value,
        };
    }
}
