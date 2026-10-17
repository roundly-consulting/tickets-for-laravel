<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

use Illuminate\Validation\Rule;
use RoundlyConsulting\Tickets\Enums\LinkType;

/**
 * `POST /tickets/{ticket}/links` — `{ticket: reference, type}`.
 *
 * @internal
 */
final class LinkRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'max:32'],
            'type' => ['required', 'string', Rule::enum(LinkType::class)],
        ];
    }

    public function reference(): string
    {
        return (string) $this->stringValue('ticket');
    }

    public function type(): LinkType
    {
        return LinkType::from((string) $this->stringValue('type'));
    }
}
