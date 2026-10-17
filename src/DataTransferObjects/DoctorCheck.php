<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * One line of `tickets:doctor`: a section, a stable code, a message and its level
 * (`error`, `warning` or `ok`).
 */
final readonly class DoctorCheck
{
    public function __construct(
        public string $section,
        public string $code,
        public string $message,
        public string $level,
    ) {}

    public function isError(): bool
    {
        return $this->level === 'error';
    }
}
