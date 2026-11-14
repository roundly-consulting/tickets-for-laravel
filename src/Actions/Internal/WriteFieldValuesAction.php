<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Internal;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Attributes\AttributesManager;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldDefinition;
use RoundlyConsulting\Tickets\Exceptions\FieldNotEditableException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\Ticket;
use RoundlyConsulting\Tickets\Support\DeferredFields;
use RoundlyConsulting\Tickets\Support\TicketVisibility;

/**
 * Writes custom field values of a ticket, inside the calling action's transaction:
 *
 *  - only keys of the ticket's own field set — tickets refuses unknown keys itself, never
 *    through attributes' global strict mode;
 *  - a writer who is not an agent for the ticket (nor the system) may only touch
 *    `requester_editable` fields;
 *  - every value is validated against its field (type, options, required, the allow-listed
 *    rules), errors keyed `fields.<key>` and worded with the field's label — while the
 *    ticket's required fields are deferred (`tickets.fields.defer_required_in`, or
 *    `$deferRequired`), a required field may be left empty;
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
    public function execute(Ticket $ticket, array $values, ?Model $actor, bool $deferRequired = false): array
    {
        if ($values === []) {
            return [];
        }

        $deferRequired = $deferRequired || DeferredFields::for($ticket);

        $fields = $this->fields->forTicket($ticket);
        $limited = $actor !== null && ! TicketVisibility::isAgentFor($actor, $ticket);
        $rules = [];
        $names = [];

        foreach (array_keys($values) as $key) {
            $field = $fields[$key] ?? throw FieldNotEditableException::unknown((string) $key);

            if ($limited && ! $field->requesterEditable) {
                throw FieldNotEditableException::notEditable($field->key);
            }

            $rules = [...$rules, ...$field->validationRules('fields.'.$field->key, $deferRequired)];
            $names = [...$names, ...self::names($field)];
        }

        $this->validator->make(['fields' => $values], $rules, [], $names)->validate();

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
     * Refuse a ticket that misses a value of a required field of its field set — at creation,
     * when a category change makes a field newly required, when an agent writes fields and when
     * the ticket leaves a deferred state (`tickets.fields.defer_required_in`). Only what `$actor`
     * may write is owed: a writer who is not an agent for the ticket (a requester filing for
     * themselves) owes the `requester_editable` fields, never one they can neither see nor set;
     * an agent or the system (null) owes every one. Errors are keyed `fields.<key>`.
     */
    public function assertRequiredFilled(Ticket $ticket, ?Model $actor = null): void
    {
        $missing = $this->missingRequired($ticket, $actor);

        if ($missing === []) {
            return;
        }

        $rules = [];
        $names = [];

        foreach ($missing as $field) {
            $rules['fields.'.$field->key] = ['required'];
            $names = [...$names, ...self::names($field)];
        }

        $this->validator->make(['fields' => []], $rules, [], $names)->validate();
    }

    /**
     * The required fields of the ticket's field set it holds no value for, in field order — of
     * those `$actor` owes ({@see self::assertRequiredFilled()}).
     *
     * @return array<string, FieldDefinition>
     */
    public function missingRequired(Ticket $ticket, ?Model $actor = null): array
    {
        $ticket->unsetRelation('attachedAttributes');
        $limited = $actor !== null && ! TicketVisibility::isAgentFor($actor, $ticket);
        $missing = [];

        foreach ($this->fields->forTicket($ticket) as $field) {
            if ($limited && ! $field->requesterEditable) {
                continue;
            }

            if ($field->required && $ticket->getAttachedAttribute($field->key)?->value === null) {
                $missing[$field->key] = $field;
            }
        }

        return $missing;
    }

    /**
     * The field's label as its input's name in validation messages — the item of a multiselect
     * too.
     *
     * @return array<string, string>
     */
    private static function names(FieldDefinition $field): array
    {
        return ['fields.'.$field->key => $field->label(), 'fields.'.$field->key.'.*' => $field->label()];
    }
}
