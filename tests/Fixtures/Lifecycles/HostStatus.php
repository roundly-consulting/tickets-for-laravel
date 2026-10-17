<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Tests\Fixtures\Lifecycles;

use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Enums\StatusCategory;

/**
 * A host's own status set: three states, its own names.
 */
enum HostStatus: string implements TicketState
{
    case Triage = 'triage';
    case Working = 'working';
    case Done = 'done';

    public function category(): StatusCategory
    {
        return match ($this) {
            self::Triage => StatusCategory::Open,
            self::Working => StatusCategory::Active,
            self::Done => StatusCategory::Closed,
        };
    }
}
