<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateFieldData;
use RoundlyConsulting\Tickets\Enums\FieldType;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * `PATCH /ticket-fields/{field}` — only what is sent changes; the key, project and category
 * stay. The action checks the default against the field as changed (a 422 keyed `default`), so
 * a new type, options or rules can never leave a default the field would refuse.
 *
 * @internal
 */
final class UpdateFieldRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', 'string', Rule::enum(FieldType::class)],
            'label' => ['sometimes', 'required', self::translated()],
            'help' => ['sometimes', 'nullable', self::translated()],
            'options' => ['sometimes', 'array', 'max:200'],
            'options.*' => ['required', self::translated()],
            'rules' => ['sometimes', 'array', 'max:20'],
            'rules.*' => ['string', 'max:300'],
            'required' => ['sometimes', 'required', 'boolean'],
            'encrypted' => ['sometimes', 'required', 'boolean'],
            'default' => ['sometimes', 'nullable'],
            'requester_visible' => ['sometimes', 'required', 'boolean'],
            'requester_editable' => ['sometimes', 'required', 'boolean'],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function toData(): UpdateFieldData
    {
        return new UpdateFieldData(
            label: $this->sent('label') ? ($this->translations('label') ?? '') : new Optional,
            help: $this->sent('help') ? $this->translations('help') : new Optional,
            type: $this->sent('type') ? FieldType::from((string) $this->stringValue('type')) : new Optional,
            options: $this->sent('options') ? StoreFieldRequest::options($this->arrayValue('options')) : new Optional,
            rules: $this->sent('rules') ? $this->strings('rules') : new Optional,
            required: $this->sent('required') ? (bool) $this->flag('required') : new Optional,
            encrypted: $this->sent('encrypted') ? (bool) $this->flag('encrypted') : new Optional,
            default: $this->sent('default') ? $this->validated('default') : new Optional,
            requesterVisible: $this->sent('requester_visible') ? (bool) $this->flag('requester_visible') : new Optional,
            requesterEditable: $this->sent('requester_editable') ? (bool) $this->flag('requester_editable') : new Optional,
            position: $this->sent('position') ? (int) $this->integerValue('position') : new Optional,
            active: $this->sent('is_active') ? (bool) $this->flag('is_active') : new Optional,
        );
    }
}
