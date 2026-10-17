<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `POST /tickets/{ticket}/replies` — a public reply, optionally with a transition applied
 * atomically (its reason and payload too).
 *
 * @internal
 */
final class ReplyRequest extends ConversationRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'transition' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'reason' => ['nullable', 'string', 'max:5000'],
            'payload' => ['nullable', 'array'],
        ];
    }

    public function transition(): ?string
    {
        return $this->stringValue('transition');
    }

    public function reason(): ?string
    {
        return $this->stringValue('reason');
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $payload = [];

        foreach ($this->arrayValue('payload') as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }
}
