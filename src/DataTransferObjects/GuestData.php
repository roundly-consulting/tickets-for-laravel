<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

/**
 * The fields of a guest requester, keyed by the names declared in `tickets.guests.fields`
 * (a json-payload-shaped map whose keys the host configures).
 */
final readonly class GuestData
{
    /**
     * @param  array<string, scalar|null>  $fields
     */
    public function __construct(
        public array $fields,
    ) {}
}
