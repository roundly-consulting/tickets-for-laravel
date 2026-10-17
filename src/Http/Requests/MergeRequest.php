<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `POST /tickets/{ticket}/merge` — `{target: reference, reason?}`.
 *
 * @internal
 */
final class MergeRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string', 'max:32'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function target(): string
    {
        return (string) $this->stringValue('target');
    }

    public function reason(): ?string
    {
        return $this->stringValue('reason');
    }
}
