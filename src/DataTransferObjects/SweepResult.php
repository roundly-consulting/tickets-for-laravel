<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * What one `Tickets::sweep()` flagged: how many tickets became overdue and how many due soon.
 */
final readonly class SweepResult
{
    public function __construct(
        public int $overdue,
        public int $dueSoon,
    ) {}

    public function total(): int
    {
        return $this->overdue + $this->dueSoon;
    }
}
