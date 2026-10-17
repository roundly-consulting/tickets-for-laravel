<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Tickets\Http\Viewer;
use RoundlyConsulting\Tickets\Models\TicketField;

/**
 * A custom field definition. Everyone sees what a form needs (key, type, label, help, options,
 * whether it is required); agents also see its rules, default, encryption and who may see and
 * edit it.
 *
 * @property TicketField $resource
 *
 * @internal
 */
final class TicketFieldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $field = $this->resource;
        $locale = app()->getLocale();

        $data = [
            'id' => $field->getKey(),
            'key' => $field->key,
            'type' => $field->type->value,
            'label' => $field->label,
            'help' => $field->help,
            'options' => array_map(static function (array $option) use ($locale): array {
                $label = $option['label'] ?? null;

                return [
                    'value' => $option['value'] ?? null,
                    'label' => is_array($label) ? ($label[$locale] ?? (array_values($label)[0] ?? null)) : $label,
                ];
            }, $field->options ?? []),
            'required' => $field->required,
            'category_id' => $field->category_id,
            'project' => $field->project_type === null ? null : Party::project($field->project),
            'position' => $field->position,
        ];

        if (Viewer::of($request)->isAgent()) {
            $data += [
                'translations' => ['label' => $field->getTranslations('label'), 'help' => $field->getTranslations('help')],
                'rules' => $field->rules ?? [],
                'default' => $field->default,
                'encrypted' => $field->encrypted,
                'requester_visible' => $field->requester_visible,
                'requester_editable' => $field->requester_editable,
                'is_active' => $field->is_active,
            ];
        }

        return $data;
    }
}
