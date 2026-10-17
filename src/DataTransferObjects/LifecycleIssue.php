<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * One finding of the ticket lifecycle contract check: an error (the package cannot work with
 * the lifecycle) or a warning (it can, but something is off).
 */
final readonly class LifecycleIssue
{
    public function __construct(
        public string $code,
        public string $message,
        public bool $error,
    ) {}
}
