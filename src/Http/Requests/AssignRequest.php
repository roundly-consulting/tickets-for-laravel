<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;

/**
 * `PUT /tickets/{ticket}/assignee` — `{assignee: {type, id}}`, or `{claim: true}` to take it.
 *
 * @internal
 */
final class AssignRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...self::morphRules('assignee'),
            'assignee' => ['required_without:claim', 'prohibits:claim', 'array:type,id'],
            'claim' => ['sometimes', 'accepted'],
        ];
    }

    public function claims(): bool
    {
        return $this->flag('claim') === true;
    }

    public function assigneeReference(): ?MorphReference
    {
        return $this->morph('assignee');
    }
}
