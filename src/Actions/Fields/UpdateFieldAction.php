<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Actions\Fields;

use RoundlyConsulting\Tickets\DataTransferObjects\UpdateFieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Exceptions\FieldTypeLockedException;
use RoundlyConsulting\Tickets\Fields\FieldSetResolver;
use RoundlyConsulting\Tickets\Models\TicketField;
use RoundlyConsulting\Tickets\Support\AdvisoryLock;
use RoundlyConsulting\Tickets\Support\FieldInput;
use RoundlyConsulting\Tickets\Support\Optional;
use RoundlyConsulting\Tickets\Support\TicketModels;
use RoundlyConsulting\Tickets\Support\TicketTransaction;

/**
 * Updates a field definition under the `lock:fields` advisory row. Once tickets hold values of
 * the field, its type and encryption are locked and its options may only grow — a select option
 * a value uses cannot go; a multiselect keeps every option (its values are json lists no query
 * can search).
 */
final readonly class UpdateFieldAction
{
    public function __construct(
        private FieldSetResolver $fields,
    ) {}

    public function execute(TicketField $field, UpdateFieldData $data): TicketField
    {
        return TicketTransaction::run($field, function () use ($field, $data): TicketField {
            AdvisoryLock::acquire(AdvisoryLock::FIELDS);

            $key = $field->key;
            $type = Optional::given($data->type) ? $data->type : $field->type;
            $used = $this->hasValues($field);

            if ($type !== $field->type && $used) {
                throw FieldTypeLockedException::type($key);
            }

            if (Optional::given($data->encrypted) && $data->encrypted !== $field->encrypted && $used) {
                throw FieldTypeLockedException::encryption($key);
            }

            $changes = [];

            if (Optional::given($data->options) || $type !== $field->type) {
                $options = FieldInput::options($key, $type, Optional::given($data->options) ? $data->options : $this->optionInput($field, $type));

                if ($used) {
                    $this->assertOptionsKept($field, $options);
                }

                $changes['options'] = $options;
            }

            $changes = [
                ...$changes,
                ...(Optional::given($data->label) ? ['label' => FieldInput::label($key, $data->label)] : []),
                ...(Optional::given($data->help) ? ['help' => $data->help] : []),
                ...(Optional::given($data->rules) ? ['rules' => FieldInput::rules($key, $data->rules) ?: null] : []),
                ...(Optional::given($data->required) ? ['required' => $data->required] : []),
                ...(Optional::given($data->encrypted) ? ['encrypted' => $data->encrypted] : []),
                ...(Optional::given($data->default) ? ['default' => $data->default] : []),
                ...(Optional::given($data->requesterVisible) ? ['requester_visible' => $data->requesterVisible] : []),
                ...(Optional::given($data->requesterEditable) ? ['requester_editable' => $data->requesterEditable] : []),
                ...(Optional::given($data->position) ? ['position' => $data->position] : []),
                ...(Optional::given($data->active) ? ['is_active' => $data->active] : []),
                'type' => $type,
            ];

            $field->forceFill($changes)->save();
            $this->fields->flush();

            return $field;
        });
    }

    /**
     * Whether any ticket of the field's scope holds a value under its key.
     */
    private function hasValues(TicketField $field): bool
    {
        $tickets = TicketModels::ticket()::withTrashed()->whereHasAttribute($field->key);

        if ($field->project_type !== null) {
            $tickets->where('project_type', $field->project_type)->where('project_id', $field->project_id);
        }

        return $tickets->exists();
    }

    /**
     * The stored options in the builder's `value => label` form, when the type changes without
     * new options.
     *
     * @return array<string, array<string, string>|string>
     */
    private function optionInput(TicketField $field, FieldType $type): array
    {
        if (! $type->hasOptions()) {
            return [];
        }

        $input = [];

        foreach ($field->options ?? [] as $option) {
            $label = $option['label'] ?? [];
            $input[(string) ($option['value'] ?? '')] = is_array($label) ? $label : (string) $label;
        }

        return $input;
    }

    /**
     * @param  list<array{value: string, label: array<string, string>}>|null  $options
     */
    private function assertOptionsKept(TicketField $field, ?array $options): void
    {
        $kept = array_column($options ?? [], 'value');
        $dropped = array_values(array_diff(
            array_map(static fn (array $option): string => (string) ($option['value'] ?? ''), $field->options ?? []),
            $kept,
        ));

        if ($dropped === []) {
            return;
        }

        if ($field->type === FieldType::Multiselect) {
            throw FieldTypeLockedException::optionsInUse($field->key, $dropped);
        }

        $inUse = array_values(array_filter(
            $dropped,
            fn (string $value): bool => TicketModels::ticket()::withTrashed()->whereAttribute($field->key, $value)->exists(),
        ));

        if ($inUse !== []) {
            throw FieldTypeLockedException::optionsInUse($field->key, $inUse);
        }
    }
}
