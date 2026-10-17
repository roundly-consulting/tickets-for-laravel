<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `GET /tickets/{ticket}/comments` — `?internal=1` asks for every message (agents only).
 *
 * @internal
 */
final class ListCommentsRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'internal' => ['nullable', 'boolean'],
        ];
    }

    public function internal(): bool
    {
        return $this->flag('internal') === true;
    }
}
