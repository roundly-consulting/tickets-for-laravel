<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Exceptions\FieldNotEditableException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\Agents;

/**
 * Writes custom field values of a ticket, inside the calling action's transaction:
 *
 *  - only keys of the ticket's own field set — tickets refuses unknown keys itself, never
 *    through attributes' global strict mode;
 *  - a writer who is not an agent (nor the system) may only touch `requester_editable` fields;
 *  - every value is validated against its field (type, options, required, the allow-listed
 *    rules), errors keyed `fields.<key>`;
 *  - a null clears the value.
 *
 * Returns the keys written — never the values.
 *
 * @internal
 */
final readonly class WriteFieldValuesAction
{
    public function __construct(
        private FieldSetResolver $fields,
        private AttributesManager $attributes,
        private ValidatorFactory $validator,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function execute(Ticket $ticket, array $values, ?Model $actor): array
    {
        if ($values === []) {
            return [];
        }

        $fields = $this->fields->forTicket($ticket);
        $limited = $actor !== null && ! Agents::is($actor);
        $rules = [];

        foreach (array_keys($values) as $key) {
            $field = $fields[$key] ?? throw FieldNotEditableException::unknown((string) $key);

            if ($limited && ! $field->requesterEditable) {
                throw FieldNotEditableException::notEditable($field->key);
            }

            $rules = [...$rules, ...$field->validationRules('fields.'.$field->key)];
        }

        $this->validator->make(['fields' => $values], $rules)->validate();

        $set = array_filter($values, static fn (mixed $value): bool => $value !== null);
        $cleared = array_keys(array_diff_key($values, $set));
        $owner = $this->attributes->for($ticket);

        if ($set !== []) {
            $owner->setMany($set);
        }

        if ($cleared !== []) {
            $owner->forget(array_map(strval(...), $cleared));
        }

        $ticket->unsetRelation('attachedAttributes');

        return array_map(strval(...), array_keys($values));
    }

    /**
     * Fill the fields' defaults the ticket has no value for, as the system — at creation only.
     */
    public function applyDefaults(Ticket $ticket): void
    {
        $defaults = [];

        foreach ($this->fields->forTicket($ticket) as $field) {
            if ($field->default !== null && ! $ticket->hasAttachedAttribute($field->key)) {
                $defaults[$field->key] = $field->default;
            }
        }

        $this->execute($ticket, $defaults, null);
    }

    /**
     * Refuse a ticket that misses a value of a required field of its field set — at creation
     * and when a category change makes a field newly required.
     */
    public function assertRequiredFilled(Ticket $ticket): void
    {
        $ticket->unsetRelation('attachedAttributes');
        $missing = [];

        foreach ($this->fields->forTicket($ticket) as $field) {
            if ($field->required && $ticket->getAttachedAttribute($field->key)?->value === null) {
                $missing['fields.'.$field->key] = [$this->requiredMessage($field)];
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }
    }

    private function requiredMessage(FieldDefinition $field): string
    {
        $line = __('validation.required', ['attribute' => $field->label[app()->getLocale()] ?? $field->key]);

        return is_string($line) ? $line : 'validation.required';
    }
}
