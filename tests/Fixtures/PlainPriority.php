<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures;

use RoundlyConsulting\Tickets\Contracts\Priority;

/**
 * A host priority enum with neither labels nor colours.
 */
enum PlainPriority: string implements Priority
{
    case P1 = 'p1';

    public function weight(): int
    {
        return 1;
    }
}
