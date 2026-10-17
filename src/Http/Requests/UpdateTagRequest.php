<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\DataTransferObjects\UpdateTagData;
use RoundlyConsulting\Tickets\Support\Optional;

/**
 * `PATCH /ticket-tags/{tag}` — a new name and/or colour (`null` removes the colour).
 *
 * @internal
 */
final class UpdateTagRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:64'],
            'color' => ['sometimes', 'nullable', 'string', 'max:7'],
        ];
    }

    public function toData(): UpdateTagData
    {
        return new UpdateTagData(
            name: $this->sent('name') ? (string) $this->stringValue('name') : new Optional,
            color: $this->sent('color') ? $this->stringValue('color') : new Optional,
        );
    }
}
