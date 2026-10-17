<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Models\TicketField;

/**
 * A custom field as it applies to a ticket: the definition row, resolved.
 */
final readonly class FieldDefinition
{
    /**
     * @param  array<string, string>  $label
     * @param  list<string>  $options  the allowed values of a select / multiselect
     * @param  list<string>  $rules  the definition's own allow-listed rules
     */
    public function __construct(
        public int $id,
        public string $key,
        public FieldType $type,
        public array $label,
        public array $options,
        public array $rules,
        public bool $required,
        public bool $encrypted,
        public mixed $default,
        public bool $requesterVisible,
        public bool $requesterEditable,
        public ?int $categoryId,
    ) {}

    public static function from(TicketField $field): self
    {
        $label = $field->getTranslations('label');

        return new self(
            id: (int) $field->getKey(),
            key: $field->key,
            type: $field->type,
            label: $label,
            options: array_map(static fn (array $option): string => (string) ($option['value'] ?? ''), $field->options ?? []),
            rules: $field->rules ?? [],
            required: $field->required,
            encrypted: $field->encrypted,
            default: $field->default,
            requesterVisible: $field->requester_visible,
            requesterEditable: $field->requester_editable,
            categoryId: $field->category_id,
        );
    }

    /**
     * The raw attribute definition attributes-for-laravel stores the value under — its type,
     * encryption and default. Validation runs in tickets, before the write.
     *
     * @return array<string, mixed>
     */
    public function attributeDefinition(): array
    {
        return [
            'type' => $this->type->attributeType()->value,
            'encrypted' => $this->encrypted,
            'default' => $this->default,
        ];
    }

    /**
     * The validation rules of a value, keyed as `$prefix` (and `$prefix.*` for the items of a
     * multiselect).
     *
     * @return array<string, list<mixed>>
     */
    public function validationRules(string $prefix): array
    {
        $presence = $this->required ? ['required'] : ['nullable'];

        $rules = match ($this->type) {
            FieldType::Text => ['string', 'max:255'],
            FieldType::Textarea => ['string', 'max:65535'],
            FieldType::Email => ['string', 'email:rfc'],
            FieldType::Url => ['string', 'url'],
            FieldType::Number => ['integer'],
            FieldType::Decimal => ['numeric'],
            FieldType::Boolean => ['boolean'],
            FieldType::Date, FieldType::DateTime => ['date'],
            FieldType::Select => ['string', $this->in()],
            FieldType::Multiselect => ['array'],
        };

        $validation = [$prefix => [...$presence, ...$rules, ...$this->rules]];

        if ($this->type === FieldType::Multiselect) {
            $validation[$prefix.'.*'] = ['string', $this->in()];
        }

        return $validation;
    }

    private function in(): In
    {
        return Rule::in($this->options);
    }
}
