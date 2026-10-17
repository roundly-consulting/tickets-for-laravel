<?php

declare(strict_types=1);

namespace RoundlyConsulting\Tickets\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Contracts\HasColor;
use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Tickets\Contracts\TicketState;
use RoundlyConsulting\Tickets\Lifecycle\TicketLifecycle;

/**
 * The states of the shipped {@see TicketLifecycle}.
 */
#[TranslatedLabels('tickets::enums.ticket_status')]
enum TicketStatus: string implements HasColor, TicketState
{
    use Helpers;

    case New = 'new';
    case Open = 'open';
    case InProgress = 'in_progress';
    /** Waiting on the requester. */
    case Pending = 'pending';
    /** Waiting on a third party. */
    case OnHold = 'on_hold';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function category(): StatusCategory
    {
        return match ($this) {
            self::New, self::Open => StatusCategory::Open,
            self::InProgress => StatusCategory::Active,
            self::Pending, self::OnHold => StatusCategory::Waiting,
            self::Resolved => StatusCategory::Resolved,
            self::Closed => StatusCategory::Closed,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'sky',
            self::Open => 'blue',
            self::InProgress => 'indigo',
            self::Pending => 'amber',
            self::OnHold => 'orange',
            self::Resolved => 'green',
            self::Closed => 'gray',
        };
    }
}
