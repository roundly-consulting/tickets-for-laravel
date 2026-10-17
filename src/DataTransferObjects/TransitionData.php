<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * A lifecycle transition to apply to a ticket: its name, the reason (required by some, such
 * as `hold` and `dismiss`) and the payload its rules validate (`resolution` on `resolve`).
 */
final readonly class TransitionData
{
    /**
     * @param  array<string, mixed>  $payload  the transition's json payload, validated by its rules
     */
    public function __construct(
        public string $transition,
        public ?string $reason = null,
        public array $payload = [],
    ) {}
}
