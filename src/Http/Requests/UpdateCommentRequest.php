<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `PATCH /tickets/{ticket}/comments/{comment}` — the new body.
 *
 * @internal
 */
final class UpdateCommentRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:65535'],
        ];
    }

    public function body(): string
    {
        return (string) $this->stringValue('body');
    }
}
