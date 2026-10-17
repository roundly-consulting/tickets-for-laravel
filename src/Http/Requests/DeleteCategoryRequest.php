<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `DELETE /ticket-categories/{category}` — `?reassign_to=` moves its tickets first.
 *
 * @internal
 */
final class DeleteCategoryRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reassign_to' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function reassignTo(): ?int
    {
        return $this->integerValue('reassign_to');
    }
}
