<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\DataTransferObjects;

use RoundlyConsulting\Tickets\Support\Optional;

/**
 * A partial tag update: a new name (its slug follows), a new colour (null clears it). A tag's
 * project is fixed at creation.
 */
final readonly class UpdateTagData
{
    public function __construct(
        public string|Optional $name = new Optional,
        public string|null|Optional $color = new Optional,
    ) {}
}
