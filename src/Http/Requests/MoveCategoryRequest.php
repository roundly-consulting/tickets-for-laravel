<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `POST /ticket-categories/{category}/move` — `{parent_id: id|null, position?}`.
 *
 * @internal
 */
final class MoveCategoryRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'parent_id' => ['present', 'nullable', 'integer', 'min:1'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function parentId(): ?int
    {
        return $this->integerValue('parent_id');
    }

    public function position(): ?int
    {
        return $this->integerValue('position');
    }
}
