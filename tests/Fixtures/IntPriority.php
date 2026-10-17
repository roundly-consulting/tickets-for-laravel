<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Contracts\Priority;

enum IntPriority: int implements Priority
{
    case Low = 1;

    public function weight(): int
    {
        return $this->value;
    }
}
