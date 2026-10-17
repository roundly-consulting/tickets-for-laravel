<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Tickets\Actions\ExampleTicketsAction;

/**
 * REPLACE ME — the input of {@see ExampleTicketsAction}.
 *
 * Actions take a DTO, never a shape array. Rename this alongside the action it feeds.
 */
final readonly class ExampleTicketsData
{
    public function __construct(
        public string $name,
    ) {}
}
