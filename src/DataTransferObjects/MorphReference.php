<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * An untrusted `{type, id}` pair from API input: `type` is an alias of one of the allow-lists
 * (`tickets.requesters`, `assignables`, `watchables`, `projects`), never a class name.
 */
final readonly class MorphReference
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    /**
     * `alias:key` (`user:42`), as list filters and path segments spell it; null when it is not
     * of that shape.
     */
    public static function parse(string $value): ?self
    {
        $parts = explode(':', $value, 2);

        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? new self($parts[0], $parts[1]) : null;
    }
}
