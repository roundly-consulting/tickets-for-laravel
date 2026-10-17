<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Tickets\DataTransferObjects\FieldData;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\Enums\FieldType;

/**
 * `POST /ticket-fields` — a custom field definition. Its key, options and rules are checked by
 * the action (allow-listed rules only).
 *
 * @internal
 */
final class StoreFieldRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', Rule::enum(FieldType::class)],
            'label' => ['required', self::translated()],
            'help' => ['nullable', self::translated()],
            'options' => ['nullable', 'array', 'max:200'],
            'options.*' => ['required', self::translated()],
            'rules' => ['nullable', 'array', 'max:20'],
            'rules.*' => ['string', 'max:300'],
            'required' => ['nullable', 'boolean'],
            'encrypted' => ['nullable', 'boolean'],
            'default' => ['nullable'],
            'requester_visible' => ['nullable', 'boolean'],
            'requester_editable' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            ...self::morphRules('project'),
            'category_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function projectReference(): ?MorphReference
    {
        return $this->morph('project');
    }

    public function toData(?Model $project): FieldData
    {
        return new FieldData(
            key: (string) $this->stringValue('key'),
            type: FieldType::from((string) $this->stringValue('type')),
            label: $this->translations('label') ?? '',
            help: $this->translations('help'),
            options: self::options($this->arrayValue('options')),
            rules: $this->strings('rules'),
            required: $this->flag('required') ?? false,
            encrypted: $this->flag('encrypted') ?? false,
            default: $this->validated('default'),
            requesterVisible: $this->flag('requester_visible') ?? true,
            requesterEditable: $this->flag('requester_editable') ?? false,
            position: $this->integerValue('position') ?? 0,
            active: $this->flag('is_active') ?? true,
            project: $project,
            category: $this->integerValue('category_id'),
        );
    }

    /**
     * Option value => label (a string, or a locale map).
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, array<string, string>|string>
     */
    public static function options(array $options): array
    {
        $normalised = [];

        foreach ($options as $value => $label) {
            if (is_string($label)) {
                $normalised[(string) $value] = $label;
            } elseif (is_array($label)) {
                $normalised[(string) $value] = array_map(strval(...), array_filter($label, is_string(...)));
            }
        }

        return $normalised;
    }
}
