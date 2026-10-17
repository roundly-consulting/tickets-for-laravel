<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Tickets\DataTransferObjects\MorphReference;

/**
 * `PATCH /tickets/{ticket}` — only what is sent changes; `null` clears the description, the
 * category or the due date. The policy decides per attribute: the requester may touch the
 * subject and description only.
 *
 * @internal
 */
final class UpdateTicketRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'priority' => ['sometimes', 'required', 'string', 'max:32'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            ...self::morphRules('requester'),
            'requester' => ['sometimes', 'required', 'array:type,id'],
        ];
    }

    /**
     * The attributes the update touches, as the policy names them.
     *
     * @return list<string>
     */
    public function touched(): array
    {
        return array_values(array_filter([
            $this->sent('subject') ? 'subject' : null,
            $this->sent('description') ? 'description' : null,
            $this->sent('priority') ? 'priority' : null,
            $this->sent('category_id') ? 'category' : null,
            $this->sent('due_at') ? 'due_at' : null,
            $this->sent('requester') ? 'requester' : null,
        ]));
    }

    public function subject(): ?string
    {
        return $this->stringValue('subject');
    }

    public function description(): ?string
    {
        return $this->stringValue('description');
    }

    public function priority(): ?string
    {
        return $this->stringValue('priority');
    }

    public function categoryId(): ?int
    {
        return $this->integerValue('category_id');
    }

    public function dueAt(): ?CarbonImmutable
    {
        $value = $this->stringValue('due_at');

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    public function requesterReference(): ?MorphReference
    {
        return $this->morph('requester');
    }
}
