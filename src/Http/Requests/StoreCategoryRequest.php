<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;

/**
 * `POST /ticket-categories`.
 *
 * @internal
 */
final class StoreCategoryRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', self::translated()],
            'description' => ['nullable', self::translated()],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            ...self::morphRules('project'),
            'position' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'default_priority' => ['nullable', 'string', 'max:32'],
            ...self::morphRules('default_assignee'),
            'default_due_in' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * @return array<string, string>|string
     */
    public function name(): array|string
    {
        return $this->translations('name') ?? '';
    }

    /**
     * @return array<string, string>|string|null
     */
    public function description(): array|string|null
    {
        return $this->translations('description');
    }

    public function parentId(): ?int
    {
        return $this->integerValue('parent_id');
    }

    public function projectReference(): ?MorphReference
    {
        return $this->morph('project');
    }

    public function position(): int
    {
        return $this->integerValue('position') ?? 0;
    }

    public function active(): bool
    {
        return $this->flag('is_active') ?? true;
    }

    public function defaultPriority(): ?string
    {
        return $this->stringValue('default_priority');
    }

    public function defaultAssigneeReference(): ?MorphReference
    {
        return $this->morph('default_assignee');
    }

    public function defaultDueIn(): ?string
    {
        return $this->stringValue('default_due_in');
    }
}
