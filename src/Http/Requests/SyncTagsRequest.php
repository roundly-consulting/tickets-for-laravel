<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `PUT /tickets/{ticket}/tags` — the ticket's whole tag list, by name.
 *
 * @internal
 */
final class SyncTagsRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tags' => ['present', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return $this->strings('tags');
    }
}
