<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;
use RoundlyConsulting\Tickets\DataTransferObjects\UpdateCategoryData;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * `PATCH /ticket-categories/{category}` — only what is sent changes. Moving it is
 * `POST {category}/move`.
 *
 * @internal
 */
final class UpdateCategoryRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', self::translated()],
            'description' => ['sometimes', 'nullable', self::translated()],
            'position' => ['sometimes', 'required', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'default_priority' => ['sometimes', 'nullable', 'string', 'max:32'],
            ...self::morphRules('default_assignee'),
            'default_assignee' => ['sometimes', 'nullable', 'array:type,id'],
            'default_due_in' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function defaultAssigneeReference(): ?MorphReference
    {
        return $this->morph('default_assignee');
    }

    /**
     * The update, with the default assignee the controller resolved from its alias.
     */
    public function toData(?Model $defaultAssignee): UpdateCategoryData
    {
        return new UpdateCategoryData(
            name: $this->sent('name') ? ($this->translations('name') ?? '') : new Optional,
            description: $this->sent('description') ? $this->translations('description') : new Optional,
            position: $this->sent('position') ? (int) $this->integerValue('position') : new Optional,
            active: $this->sent('is_active') ? (bool) $this->flag('is_active') : new Optional,
            defaultPriority: $this->sent('default_priority') ? $this->stringValue('default_priority') : new Optional,
            defaultAssignee: $this->sent('default_assignee') ? $defaultAssignee : new Optional,
            defaultDueIn: $this->sent('default_due_in') ? $this->stringValue('default_due_in') : new Optional,
        );
    }
}
