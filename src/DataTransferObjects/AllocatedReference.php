<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * A reference handed out by the sequence allocator: `SUP-42` = prefix `SUP`, number 42 in
 * the sequence scope (`global`, or `{morph alias}:{key}` for a project).
 */
final readonly class AllocatedReference
{
    public function __construct(
        public string $scope,
        public int $number,
        public string $reference,
    ) {}
}
