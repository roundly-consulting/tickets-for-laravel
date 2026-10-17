<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;

/**
 * A category, field or tag list — global, or with `?project=alias:key` a project's too; fields
 * also take `?category=` (the fields that apply to tickets of that category).
 *
 * @internal
 */
final class ScopedListRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'project' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The `project` reference, or null; a value not shaped `alias:key` is an unknown alias.
     */
    public function projectReference(): ?MorphReference
    {
        $value = $this->stringValue('project');

        return $value === null ? null : (MorphReference::parse($value) ?? new MorphReference($value, ''));
    }

    public function category(): ?int
    {
        return $this->integerValue('category');
    }
}
