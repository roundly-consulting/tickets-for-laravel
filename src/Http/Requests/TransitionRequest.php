<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Http\Requests;

/**
 * `POST /tickets/{ticket}/transitions/{transition}` (and the guest twin) — the reason and the
 * payload the transition's own rules validate.
 *
 * @internal
 */
final class TransitionRequest extends TicketsRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:5000'],
            'payload' => ['nullable', 'array'],
        ];
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
